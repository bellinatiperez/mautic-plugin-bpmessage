<?php

declare(strict_types=1);

namespace MauticPlugin\MauticBpMessageBundle\Model;

use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Client;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\MauticBpMessageBundle\Entity\BpMessageQueue;
use MauticPlugin\MauticBpMessageBundle\Entity\BpMessageQueueRepository;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class BpMessageModel
{
    /**
     * Campos extras preservados no snapshot de configuração para execução dos formulários de lote.
     */
    public static array $extraKeys = [
        'ServiceType',
        'user',
        'idQuotaSettings',
        'idServiceSettings',
        'idBookBusinessSendGroup',
        'idForeignBookBusiness',
        'imageUrl',
        'imageName',
        'idTemplate',
        'text',
        'data',
        'variables',
        'name',
        'startDate',
        'endDate',
    ];

    private Client $httpClient;

    public function __construct(
        private EventDispatcherInterface $dispatcher,
        private EntityManagerInterface $em,
        private CoreParametersHelper $params,
        private LoggerInterface $logger
    ) {
        $this->httpClient = new Client();
    }

    public function queueContact(array $config, Lead $contact): void
    {
        $headers = $this->buildStaticHeaders($config);
        $flow = (string) ($config['flow'] ?? 'messages_batch');
        $snapshot = [
            'url'            => (string) ($config['url'] ?? ''),
            'headers'        => $headers,
            'method'         => (string) ($config['method'] ?? 'post'),
            'batch_size'     => (int) ($config['batch_size'] ?? 50),
            'batch_interval' => (int) ($config['batch_interval'] ?? 0),
            'retry_limit'    => (int) ($config['retry_limit'] ?? 3),
            'timeout'        => (int) ($config['timeout'] ?? 30),
            'flow'           => $flow,
        ];
        foreach (self::$extraKeys as $key) {
            if (array_key_exists($key, $config)) {
                $snapshot[$key] = $config[$key];
            }
        }
        $hash = hash('sha256', json_encode($snapshot));

        /** @var BpMessageQueueRepository $repo */
        $repo = $this->em->getRepository(BpMessageQueue::class);
        if (method_exists($repo, 'existsByHash') && $repo->existsByHash($hash)) {
            $this->logger->info('BpMessage queueContact: skipping duplicate config', ['hash' => $hash]);
        }

        $contactPayload = ['__leadId' => (string) $contact->getId()];

        $q = new BpMessageQueue();
        $q->setDateAdded(new \DateTime());
        $q->setPayload(json_encode($contactPayload));
        $q->setConfigHash($hash);
        $q->setConfigJson(json_encode($snapshot));
        $q->setRetries(0);
        $this->em->persist($q);
        $this->em->flush();
    }

    /**
     * Processa filas pendentes. Se informado $hash, processa apenas as filas dessa configuração.
     * Retorna relatório ['eligible' => n, 'processed' => n, 'scheduled' => n].
     */
    public function processPending(?string $hash = null): array
    {
        /** @var BpMessageQueueRepository $repo */
        $repo = $this->em->getRepository(BpMessageQueue::class);

        // Buscar todas as filas (ou filtradas por hash)
        $criteria = [];
        if ($hash) {
            $criteria['configHash'] = $hash;
        }
        $all = $repo->findBy($criteria, ['id' => 'ASC']);
        $eligible = count($all);
        if ($eligible === 0) {
            return ['eligible' => 0, 'processed' => 0, 'scheduled' => 0];
        }

        // Agrupar por configHash para executar em lote
        $groups = [];
        foreach ($all as $q) {
            $groups[$q->getConfigHash() ?? ''][] = $q;
        }

        $processed = 0;
        $scheduled = 0;

        foreach ($groups as $groupHash => $selected) {
            if (empty($selected)) { continue; }
            $config = $this->decodeConfig($selected[0]);
            $baseUrl = (string) ($config['url'] ?? '');
            $headers = $this->sanitizeHeaders($config['headers'] ?? []);
            $timeout = (int) ($config['timeout'] ?? 30);
            $retryLimit = (int) ($config['retry_limit'] ?? 3);
            $flow = (string) ($config['flow'] ?? 'messages_batch');

            try {
                switch ($flow) {
                    case 'emails_batch':
                        $result = $this->processEmailsBatch($baseUrl, $headers, $timeout, $selected, $retryLimit, $config);
                        $processed += (int) ($result['processed'] ?? 0);
                        $scheduled += (int) ($result['scheduled'] ?? 0);
                        break;
                    case 'emails_single':
                        $result = $this->processEmailsSingle($baseUrl, $headers, $timeout, $selected, $retryLimit, $config);
                        $processed += (int) ($result['processed'] ?? 0);
                        $scheduled += (int) ($result['scheduled'] ?? 0);
                        break;
                    case 'messages_single':
                        $result = $this->processMessagesSingle($baseUrl, $headers, $timeout, $selected, $retryLimit, $config);
                        $processed += (int) ($result['processed'] ?? 0);
                        $scheduled += (int) ($result['scheduled'] ?? 0);
                        break;
                    default:
                        $result = $this->processMessagesBatch($baseUrl, $headers, $timeout, [], $selected, $retryLimit, $config);
                        $processed += (int) ($result['processed'] ?? 0);
                        $scheduled += (int) ($result['scheduled'] ?? 0);
                        break;
                }
            } catch (\Throwable $e) {
                $this->logger->error('BpMessage processPending group error: '.$e->getMessage());
                $failed = $this->handleFailedBatch($selected, $retryLimit, $e->getMessage());
                $processed += (int) ($failed['deleted'] ?? 0);
                $scheduled += (int) ($failed['scheduled'] ?? 0);
            }
        }

        return ['eligible' => $eligible, 'processed' => $processed, 'scheduled' => $scheduled];
    }

    private function processMessagesBatch(string $baseUrl, array $headers, int $timeout, array $aggregatedPayload, array $selected, int $retryLimit, array $config): array
    {
        $createLotPayload = $this->buildCreateLotPayloadFromConfig($config);
        $validationLotError = $this->validateCreateLotPayload($createLotPayload);
        if (null !== $validationLotError) {
            $failed = $this->handleFailedBatch($selected, $retryLimit, 'CreateLot payload validation failed: '.$validationLotError);
            return ['processed' => $failed['deleted'], 'scheduled' => $failed['scheduled']];
        }

        $this->logger->info('BpMessage: creating lot with payload', ['payload' => $createLotPayload]);
        $batchId = $this->createLot($baseUrl, $headers, $timeout, $createLotPayload);
        if (!$batchId) {
            $this->logger->error('BpMessage: CreateLot failed - no batchId returned');
            $failed = $this->handleFailedBatch($selected, $retryLimit, 'CreateLot failed');
            return ['processed' => $failed['deleted'], 'scheduled' => $failed['scheduled']];
        }
        $this->logger->info('BpMessage: lot created successfully', ['batchId' => $batchId]);

        $messageBaseList = $this->buildMessagesPayloadFromConfig($config, 1);
        $messageBase = $messageBaseList[0] ?? [];
        $this->logger->info('BpMessage: building messages from config', ['messageBase' => $messageBase, 'selectedCount' => count($selected)]);
        $messages = [];
        foreach ($selected as $q) {
            $contactTokens = $this->decodePayload($q->getPayload());
            $leadId = isset($contactTokens['__leadId']) ? (int) $contactTokens['__leadId'] : null;
            $msg = $messageBase;
            if ($leadId) {
                $leadRepo = $this->em->getRepository(Lead::class);
                $lead = method_exists($leadRepo, 'getEntity') ? $leadRepo->getEntity($leadId) : $this->em->find(Lead::class, $leadId);
                if ($lead instanceof Lead) {
                    // Snapshot de chaves disponíveis no lead para depuração
                    try {
                        $keys = array_keys((array) $lead->getProfileFields());
                        $this->logger->info('BpMessage: lead fields keys snapshot', [
                            'leadId' => $leadId,
                            'keysPreview' => $this->stringPreview(implode(',', $keys)),
                        ]);
                    } catch (\Throwable $e) {
                        // Ignora erros de log
                    }
                    $msg['text'] = $this->parseTextFromLead($msg['text'], $lead);
                    if (isset($msg['variables']) && is_array($msg['variables'])) {
                        $msg['variables'] = $this->parseVariablesValuesFromLead($msg['variables'], $lead);
                    }
                    // Parse campos adicionais de 'data' com valores do lead
                    $additionalRaw = $config['data'] ?? [];
                    $additionalPairs = $this->parseVariables($additionalRaw);
                    foreach ($additionalPairs as $item) {
                        if (is_array($item) && isset($item['key'])) {
                            $msg[$item['key']] = $this->replaceRecursiveWithLead($item['value'] ?? '', $lead);
                        }
                    }
                }
            }
            if (isset($contactTokens['__leadId'])) {
                unset($contactTokens['__leadId']);
            }
            // Diagnóstico antes do merge
            $beforeText = (string) ($msg['text'] ?? '');
            $beforeArea = isset($msg['areaCode']) ? (string) $msg['areaCode'] : null;
            $beforePhone = isset($msg['phone']) ? (string) $msg['phone'] : null;
            $beforeName = isset($msg['contactName']) ? (string) $msg['contactName'] : null;
            foreach ($contactTokens as $k => $v) {
                // Parseia valores de contactTokens com o lead, se disponível
                if (isset($lead) && $lead instanceof Lead) {
                    $v = $this->replaceRecursiveWithLead($v, $lead);
                }
                $msg[$k] = $v;
            }
            // Passe final: parseia todos os campos string/arrays do msg com o lead para garantir resolução
            if (isset($lead) && $lead instanceof Lead) {
                $msg = $this->replaceRecursiveWithLead($msg, $lead);
            }
            // Log auxiliar para verificar texto e variáveis já parseados por lead
            $this->logger->info('BpMessage: lead parsed message', [
                'leadId' => $leadId,
                'beforeText' => $this->stringPreview($beforeText),
                'afterText' => $this->stringPreview((string)($msg['text'] ?? '')),
                'areaCodeBefore' => $beforeArea,
                'areaCodeAfter' => isset($msg['areaCode']) ? (string) $msg['areaCode'] : null,
                'phoneBefore' => $beforePhone,
                'phoneAfter' => isset($msg['phone']) ? (string) $msg['phone'] : null,
                'contactNameBefore' => $beforeName,
                'contactNameAfter' => isset($msg['contactName']) ? (string) $msg['contactName'] : null,
                'variablesCount' => isset($msg['variables']) && is_array($msg['variables']) ? count($msg['variables']) : 0,
            ]);
            $messages[] = $msg;
        }
        $messagesPayload = $messages;

        $validationMsgError = $this->validateMessagesPayload($messagesPayload);
        if (null !== $validationMsgError) {
            $this->logger->error('BpMessage: AddMessageToLot payload validation failed', ['error' => $validationMsgError, 'batchId' => $batchId]);
            $this->finishLot($baseUrl, $batchId, $headers, $timeout);
            $failed = $this->handleFailedBatch($selected, $retryLimit, 'AddMessageToLot payload validation failed: '.$validationMsgError);
            return ['processed' => $failed['deleted'], 'scheduled' => $failed['scheduled']];
        }

        // Envio em chunks para grandes volumes
        $chunkSize = max(1, (int) ($config['batch_size'] ?? 1000));
        $chunks = array_chunk($messagesPayload, $chunkSize);
        $this->logger->info('BpMessage: adding messages to lot (chunked)', [
            'batchId' => $batchId,
            'messageCount' => count($messages),
            'chunkSize' => $chunkSize,
            'chunkCount' => count($chunks),
        ]);
        foreach ($chunks as $i => $chunk) {
            $this->logger->info('BpMessage: AddMessageToLot chunk start', ['batchId' => $batchId, 'chunkIndex' => $i+1, 'chunkItems' => count($chunk)]);
            $ok = $this->addMessageToLot($baseUrl, $batchId, $chunk, $headers, $timeout);
            if (!$ok) {
                $this->logger->error('BpMessage: AddMessageToLot failed', ['batchId' => $batchId, 'chunkIndex' => $i+1]);
                $this->finishLot($baseUrl, $batchId, $headers, $timeout);
                $failed = $this->handleFailedBatch($selected, $retryLimit, 'AddMessageToLot POST failed');
                return ['processed' => $failed['deleted'], 'scheduled' => $failed['scheduled']];
            }
            $this->logger->info('BpMessage: AddMessageToLot chunk done', ['batchId' => $batchId, 'chunkIndex' => $i+1]);
        }
        $this->logger->info('BpMessage: messages added to lot successfully', ['batchId' => $batchId, 'chunkCount' => count($chunks)]);

        $this->logger->info('BpMessage: finishing lot', ['batchId' => $batchId]);
        $finished = $this->finishLot($baseUrl, $batchId, $headers, $timeout);
        if (!$finished) {
            $this->logger->error('BpMessage: FinishLot failed', ['batchId' => $batchId]);
            $failed = $this->handleFailedBatch($selected, $retryLimit, 'FinishLot failed');
            return ['processed' => $failed['deleted'], 'scheduled' => $failed['scheduled']];
        }
        $this->logger->info('BpMessage: lot finished successfully', ['batchId' => $batchId]);

        $deleted = $this->deleteProcessed($selected);
        return ['processed' => $deleted, 'scheduled' => 0];
    }

    private function processMessagesSingle(string $baseUrl, array $headers, int $timeout, array $selected, int $retryLimit, array $config): array
    {
        $deletedTotal = 0;
        foreach ($selected as $q) {
            try {
                $contactTokens = $this->decodePayload($q->getPayload());
                $leadId = isset($contactTokens['__leadId']) ? (int) $contactTokens['__leadId'] : null;

                $messageBaseList = $this->buildMessagesPayloadFromConfig($config, 1);
                $msg = $messageBaseList[0] ?? [];

                if ($leadId) {
                    $leadRepo = $this->em->getRepository(Lead::class);
                    $lead = method_exists($leadRepo, 'getEntity') ? $leadRepo->getEntity($leadId) : $this->em->find(Lead::class, $leadId);
                    if ($lead instanceof Lead) {
                        // Snapshot de chaves disponíveis no lead para depuração
                        try {
                            $keys = array_keys((array) $lead->getProfileFields());
                            $this->logger->info('BpMessage: lead fields keys snapshot', [
                                'leadId' => $leadId,
                                'keysPreview' => $this->stringPreview(implode(',', $keys)),
                            ]);
                        } catch (\Throwable $e) {
                            // Ignora erros de log
                        }
                        $msg['text'] = $this->parseTextFromLead($msg['text'], $lead);
                        if (isset($msg['variables']) && is_array($msg['variables'])) {
                            $msg['variables'] = $this->parseVariablesValuesFromLead($msg['variables'], $lead);
                        }
                        // Parse campos adicionais de 'data' com valores do lead
                        $additionalRaw = $config['data'] ?? [];
                        $additionalPairs = $this->parseVariables($additionalRaw);
                        foreach ($additionalPairs as $item) {
                            if (is_array($item) && isset($item['key'])) {
                                $msg[$item['key']] = $this->replaceRecursiveWithLead($item['value'] ?? '', $lead);
                            }
                        }
                    }
                }
                if (isset($contactTokens['__leadId'])) {
                    unset($contactTokens['__leadId']);
                }
                // Diagnóstico antes do merge
                $beforeText = (string) ($msg['text'] ?? '');
                $beforeArea = isset($msg['areaCode']) ? (string) $msg['areaCode'] : null;
                $beforePhone = isset($msg['phone']) ? (string) $msg['phone'] : null;
                $beforeName = isset($msg['contactName']) ? (string) $msg['contactName'] : null;
                foreach ($contactTokens as $k => $v) {
                    // Parseia valores de contactTokens com o lead, se disponível
                    if (isset($lead) && $lead instanceof Lead) {
                        $v = $this->replaceRecursiveWithLead($v, $lead);
                    }
                    $msg[$k] = $v;
                }

                // Passe final: parseia todos os campos string/arrays do msg com o lead para garantir resolução
                if (isset($lead) && $lead instanceof Lead) {
                    $msg = $this->replaceRecursiveWithLead($msg, $lead);
                }

                $payload = $msg;
                // Log pós-merge para diagnóstico
                $this->logger->info('BpMessage: single merge diagnostics', [
                    'leadId' => $leadId,
                    'beforeText' => $this->stringPreview($beforeText),
                    'afterText' => $this->stringPreview((string)($msg['text'] ?? '')),
                    'areaCodeBefore' => $beforeArea,
                    'areaCodeAfter' => isset($msg['areaCode']) ? (string) $msg['areaCode'] : null,
                    'phoneBefore' => $beforePhone,
                    'phoneAfter' => isset($msg['phone']) ? (string) $msg['phone'] : null,
                    'contactNameBefore' => $beforeName,
                    'contactNameAfter' => isset($msg['contactName']) ? (string) $msg['contactName'] : null,
                ]);
                $ok = $this->postPayload(rtrim($baseUrl,'/').'/api/Message/AddMessageInvoice', $headers, $timeout, $payload);
                if (!$ok) {
                    throw new \RuntimeException('AddMessageInvoice failed');
                }
                $deletedTotal += $this->deleteProcessed([$q]);
            } catch (\Throwable $e) {
                $this->logger->error('BpMessage processMessagesSingle error: '.$e->getMessage());
                $failed = $this->handleFailedBatch([$q], $retryLimit, $e->getMessage());
                $deletedTotal += (int) ($failed['deleted'] ?? 0);
            }
        }
        return ['processed' => $deletedTotal, 'scheduled' => 0];
    }

    private function processEmailsBatch(string $baseUrl, array $headers, int $timeout, array $selected, int $retryLimit, array $config): array
    {
        $createPayload = [
            'user'                   => (string) ($config['user'] ?? ''),
            'idQuotaSettings'        => (int) ($config['idQuotaSettings'] ?? 0),
            'idServiceSettings'      => (int) ($config['idServiceSettings'] ?? 0),
            'idBookBusinessSendGroup'=> (int) ($config['idBookBusinessSendGroup'] ?? 0),
            'name'                   => (string) ($config['name'] ?? ''),
        ];
        $batchId = $this->createEmailLot($baseUrl, $headers, $timeout, $createPayload);
        if (!$batchId) {
            $failed = $this->handleFailedBatch($selected, $retryLimit, 'CreateEmailLot failed');
            return ['processed' => $failed['deleted'], 'scheduled' => $failed['scheduled']];
        }

        // Montar emails a partir de config (reutilizar estrutura de messages)
        $messageBaseList = $this->buildMessagesPayloadFromConfig($config, 1);
        $messageBase = $messageBaseList[0] ?? [];
        $emails = [];
        foreach ($selected as $q) {
            $contactTokens = $this->decodePayload($q->getPayload());
            $leadId = isset($contactTokens['__leadId']) ? (int) $contactTokens['__leadId'] : null;
            $msg = $messageBase;
            if ($leadId) {
                $leadRepo = $this->em->getRepository(Lead::class);
                $lead = method_exists($leadRepo, 'getEntity') ? $leadRepo->getEntity($leadId) : $this->em->find(Lead::class, $leadId);
                if ($lead instanceof Lead) {
                    // Snapshot de chaves disponíveis no lead para depuração
                    try {
                        $keys = array_keys((array) $lead->getProfileFields());
                        $this->logger->info('BpMessage: lead fields keys snapshot', [
                            'leadId' => $leadId,
                            'keysPreview' => $this->stringPreview(implode(',', $keys)),
                        ]);
                    } catch (\Throwable $e) {
                        // Ignora erros de log
                    }
                    $msg['text'] = $this->parseTextFromLead($msg['text'], $lead);
                    if (isset($msg['variables']) && is_array($msg['variables'])) {
                        $msg['variables'] = $this->parseVariablesValuesFromLead($msg['variables'], $lead);
                    }
                    // Parse campos adicionais de 'data' com valores do lead
                    $additionalRaw = $config['data'] ?? [];
                    $additionalPairs = $this->parseVariables($additionalRaw);
                    foreach ($additionalPairs as $item) {
                        if (is_array($item) && isset($item['key'])) {
                            $msg[$item['key']] = $this->replaceRecursiveWithLead($item['value'] ?? '', $lead);
                        }
                    }
                }
            }
            if (isset($contactTokens['__leadId'])) {
                unset($contactTokens['__leadId']);
            }
            // Diagnóstico antes do merge
            $beforeText = (string) ($msg['text'] ?? '');
            $beforeArea = isset($msg['areaCode']) ? (string) $msg['areaCode'] : null;
            $beforePhone = isset($msg['phone']) ? (string) $msg['phone'] : null;
            $beforeName = isset($msg['contactName']) ? (string) $msg['contactName'] : null;
            foreach ($contactTokens as $k => $v) {
                // Parseia valores de contactTokens com o lead, se disponível
                if (isset($lead) && $lead instanceof Lead) {
                    $v = $this->replaceRecursiveWithLead($v, $lead);
                }
                $msg[$k] = $v;
            }
            // Passe final: parseia todos os campos string/arrays do msg com o lead para garantir resolução
            if (isset($lead) && $lead instanceof Lead) {
                $msg = $this->replaceRecursiveWithLead($msg, $lead);
            }
            $emails[] = $msg;
        }
        // Envio em chunks para grandes volumes
        $chunkSize = max(1, (int) ($config['batch_size'] ?? 1000));
        $chunks = array_chunk($emails, $chunkSize);
        // Log pós-merge para diagnóstico (apenas preview do primeiro item)
        if (!empty($emails)) {
            $afterText = isset($emails[0]['text']) ? (string) $emails[0]['text'] : '';
            $this->logger->info('BpMessage: emails batch merge diagnostics', [
                'leadId' => $leadId,
                'beforeText' => $this->stringPreview($beforeText),
                'afterText' => $this->stringPreview($afterText),
                'areaCodeBefore' => $beforeArea,
                'areaCodeAfter' => isset($emails[0]['areaCode']) ? (string) $emails[0]['areaCode'] : null,
                'phoneBefore' => $beforePhone,
                'phoneAfter' => isset($emails[0]['phone']) ? (string) $emails[0]['phone'] : null,
                'contactNameBefore' => $beforeName,
                'contactNameAfter' => isset($emails[0]['contactName']) ? (string) $emails[0]['contactName'] : null,
            ]);
        }
        $this->logger->info('BpMessage: adding emails to lot (chunked)', [
            'batchId' => $batchId,
            'emailCount' => count($emails),
            'chunkSize' => $chunkSize,
            'chunkCount' => count($chunks),
        ]);
        foreach ($chunks as $i => $chunk) {
            $payload = ['data' => $chunk];
            $this->logger->info('BpMessage: AddEmailToLot chunk start', ['batchId' => $batchId, 'chunkIndex' => $i+1, 'chunkItems' => count($chunk)]);
            $ok = $this->addEmailToLot($baseUrl, $batchId, $payload, $headers, $timeout);
            if (!$ok) {
                $this->finishEmailLot($baseUrl, $batchId, $headers, $timeout);
                $failed = $this->handleFailedBatch($selected, $retryLimit, 'AddEmailToLot POST failed');
                return ['processed' => $failed['deleted'], 'scheduled' => $failed['scheduled']];
            }
            $this->logger->info('BpMessage: AddEmailToLot chunk done', ['batchId' => $batchId, 'chunkIndex' => $i+1]);
        }

        $finished = $this->finishEmailLot($baseUrl, $batchId, $headers, $timeout);
        if (!$finished) {
            $failed = $this->handleFailedBatch($selected, $retryLimit, 'FinishEmailLot failed');
            return ['processed' => $failed['deleted'], 'scheduled' => $failed['scheduled']];
        }

        $deleted = $this->deleteProcessed($selected);
        return ['processed' => $deleted, 'scheduled' => 0];
    }

    private function processEmailsSingle(string $baseUrl, array $headers, int $timeout, array $selected, int $retryLimit, array $config): array
    {
        // Como placeholder: usar o mesmo conteúdo de message para o email único
        $deletedTotal = 0;
        foreach ($selected as $q) {
            try {
                $contactTokens = $this->decodePayload($q->getPayload());
                $leadId = isset($contactTokens['__leadId']) ? (int) $contactTokens['__leadId'] : null;

                $messageBaseList = $this->buildMessagesPayloadFromConfig($config, 1);
                $msg = $messageBaseList[0] ?? [];

                if ($leadId) {
                    $leadRepo = $this->em->getRepository(Lead::class);
                    $lead = method_exists($leadRepo, 'getEntity') ? $leadRepo->getEntity($leadId) : $this->em->find(Lead::class, $leadId);
                    if ($lead instanceof Lead) {
                        $msg['text'] = $this->parseTextFromLead($msg['text'], $lead);
                        if (isset($msg['variables']) && is_array($msg['variables'])) {
                            $msg['variables'] = $this->parseVariablesValuesFromLead($msg['variables'], $lead);
                        }
                    }
                }
                if (isset($contactTokens['__leadId'])) {
                    unset($contactTokens['__leadId']);
                }
                foreach ($contactTokens as $k => $v) {
                    $msg[$k] = $v;
                }

                $payload = $msg;
                $ok = $this->postPayload(rtrim($baseUrl,'/').'/api/Email/AddEmailInvoice', $headers, $timeout, $payload);
                if (!$ok) {
                    throw new \RuntimeException('AddEmailInvoice failed');
                }
                $deletedTotal += $this->deleteProcessed([$q]);
            } catch (\Throwable $e) {
                $this->logger->error('BpMessage processEmailsSingle error: '.$e->getMessage());
                $failed = $this->handleFailedBatch([$q], $retryLimit, $e->getMessage());
                $deletedTotal += (int) ($failed['deleted'] ?? 0);
            }
        }
        return ['processed' => $deletedTotal, 'scheduled' => 0];
    }

    private function buildCreateLotPayloadFromConfig(array $config): array
    {
        $nowIso = $this->nowIso8601WithMsUtc();
        $payload = [
            'name'                     => (string) ($config['name'] ?? ''),
            'ServiceType'              => (int) ($config['ServiceType'] ?? 1),
            'user'                     => (string) ($config['user'] ?? ''),
            'idQuotaSettings'          => (int) ($config['idQuotaSettings'] ?? 0),
            'idServiceSettings'        => (int) ($config['idServiceSettings'] ?? 0),
            'imageUrl'                 => (string) ($config['imageUrl'] ?? ''),
            'imageName'                => (string) ($config['imageName'] ?? ''),
            'startDate'                => (string) ($config['startDate'] ?? $nowIso),
            'endDate'                  => (string) ($config['endDate'] ?? $nowIso),
        ];

        // Incluir idBookBusinessSendGroup apenas quando houver valor (>0)
        $groupRaw = $config['idBookBusinessSendGroup'] ?? null;
        if ($groupRaw !== null && $groupRaw !== '' && (int) $groupRaw > 0) {
            $payload['idBookBusinessSendGroup'] = (int) $groupRaw;
        }
        return $payload;
    }

    private function nowIso8601WithMsUtc(): string
    {
        $dt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return $dt->format('Y-m-d\TH:i:s.v\Z');
    }

    private function validateCreateLotPayload(array $payload): ?string
    {
        if (!is_array($payload)) {
            return 'CreateLot payload must be an array';
        }
        return null;
    }

    private function buildMessagesPayloadFromConfig(array $config, int $count): array
    {
        $variables = $this->parseVariables($config['variables'] ?? []);
        $additional = $this->parseVariables($config['data'] ?? []);
        $idForeign = (string) ($config['idForeignBookBusiness'] ?? ($config['bookBusinessForeignId'] ?? ''));
        $base = [
            'text'                  => (string) ($config['text'] ?? ''),
            'idForeignBookBusiness' => $idForeign,
            'variables'             => $variables,
            'idTemplate'            => (string) ($config['idTemplate'] ?? ''),
            'idServiceType'         => (int) ($config['ServiceType'] ?? 1),
        ];

        $messages = [];
        for ($i = 0; $i < max(1, $count); $i++) {
            $msg = $base;
            // Mescla itens de "data" como campos adicionais por mensagem
            foreach ($additional as $item) {
                if (is_array($item) && isset($item['key'])) {
                    $msg[$item['key']] = $item['value'] ?? '';
                }
            }
            $messages[] = $msg;
        }
        return $messages;
    }

    private function parseVariables(mixed $variables): array
    {
        $result = [];
        if (is_string($variables)) {
            $lines = preg_split('/\r?\n/', trim($variables));
            foreach ($lines as $line) {
                if ($line === '') { continue; }
                $parts = explode('=', $line, 2);
                $key = trim($parts[0] ?? '');
                $raw = trim($parts[1] ?? '');
                if ($key === '') { continue; }
                $val = json_decode($raw, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $val = $raw;
                }
                $result[] = ['key' => $key, 'value' => $val];
            }
        } elseif (is_array($variables)) {
            foreach ($variables as $k => $v) {
                if (is_array($v) && isset($v['key'])) {
                    $key = (string) $v['key'];
                    $raw = $v['value'] ?? '';
                } else {
                    $key = is_string($k) ? $k : '';
                    $raw = $v;
                }
                if ($key === '') { continue; }
                $val = is_string($raw) ? json_decode($raw, true) : $raw;
                if (is_string($raw) && json_last_error() !== JSON_ERROR_NONE) {
                    $val = $raw;
                }
                $result[] = ['key' => $key, 'value' => $val];
            }
        }
        return $result;
    }

    private function parseText(string $text, array $vars): string
    {
        if ($text === '' || empty($vars)) {
            return $text;
        }
        $map = [];
        foreach ($vars as $pair) {
            if (!is_array($pair) || !isset($pair['key'])) {
                continue;
            }
            $key = (string) $pair['key'];
            $val = $pair['value'];
            if (is_array($val) || is_object($val)) {
                $val = json_encode($val);
            } elseif ($val === null) {
                $val = '';
            } else {
                $val = (string) $val;
            }
            $map['{{'.$key.'}}'] = $val;
            $map['{'.$key.'}'] = $val;
        }
        if (!empty($map)) {
            $text = strtr($text, $map);
        }
        return $text;
    }

    private function parseTextFromLead(string $text, Lead $lead): string
    {
        if ($text === '') {
            return $text;
        }
        $fields = $lead->getProfileFields();
        if (!is_array($fields) || empty($fields)) {
            return $text;
        }
        $map = [];
        foreach ($fields as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            if (is_array($value) || is_object($value)) {
                $value = json_encode($value);
            } elseif ($value === null) {
                $value = '';
            } else {
                $value = (string) $value;
            }

            // Constrói variações de chave para tokens (normaliza e cria aliases)
            $variants = [];
            $variants[] = (string) $key; // original
            $lower = strtolower((string) $key);
            if ($lower !== $key) {
                $variants[] = $lower; // minúsculo
            }
            $noUnderscore = str_replace('_', '', $lower);
            if ($noUnderscore !== $lower) {
                $variants[] = $noUnderscore; // sem underscore
            }

            foreach (array_unique($variants) as $k2) {
                // Suporta {{key}} e {key}
                $map['{{'.$k2.'}}'] = $value;
                $map['{'.$k2.'}'] = $value;
                // Suporta {contactfield=key}
                $map['{contactfield='.$k2.'}'] = $value;
                // Variação com chaves duplas (não comum, mas inofensivo)
                $map['{{contactfield='.$k2.'}}'] = $value;
            }
        }
        return !empty($map) ? strtr($text, $map) : $text;
    }

    private function parseVariablesValuesFromLead(array $vars, Lead $lead): array
    {
        if (empty($vars)) {
            return $vars;
        }
        $out = [];
        foreach ($vars as $pair) {
            if (!is_array($pair) || !array_key_exists('key', $pair)) {
                $out[] = $pair;
                continue;
            }
            $value = $pair['value'] ?? null;
            $pair['value'] = $this->replaceRecursiveWithLead($value, $lead);
            $out[] = $pair;
        }
        return $out;
    }

    private function replaceRecursiveWithLead(mixed $value, Lead $lead): mixed
    {
        if (is_string($value)) {
            return $this->parseTextFromLead($value, $lead);
        }
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = $this->replaceRecursiveWithLead($v, $lead);
            }
            return $value;
        }
        return $value;
    }

    private function validateMessagesPayload(array $messages): ?string
    {
        if (!is_array($messages) || empty($messages)) {
            return 'Messages payload must be a non-empty array';
        }
        $list = $messages;
        if (isset($messages['data']) && is_array($messages['data'])) {
            $list = $messages['data'];
        }
        if (empty($list)) {
            return 'Messages payload must contain at least one item';
        }
        $required = ['areaCode','phone','text','idServiceType','variables'];
        foreach ($list as $i => $msg) {
            foreach ($required as $k) {
                if (!array_key_exists($k, $msg) || $msg[$k] === '' || $msg[$k] === null) {
                    return sprintf('Message[%d] missing or empty field "%s"', $i, $k);
                }
            }
        }
        return null;
    }

    private function createLot(string $baseUrl, array $headers, int $timeout, array $payload): ?string
    {
        $url = rtrim($baseUrl,'/').'/api/Lot/CreateLot';
        try {
            $ok = $this->postPayload($url, $headers, $timeout, $payload, true);
            if (is_string($ok)) {
                $id = trim($ok);
                return $id !== '' ? $id : null;
            }
            if (!is_array($ok)) {
                return null;
            }
            return $this->extractIdFromResponse($ok) ?: ($ok['id'] ?? null);
        } catch (\Throwable $e) {
            $this->logger->error('BpMessage CreateLot error: '.$e->getMessage());
            return null;
        }
    }

    private function addMessageToLot(string $baseUrl, string $batchId, array $payload, array $headers, int $timeout): bool
    {
        $url = rtrim($baseUrl,'/').'/api/Lot/AddMessageToLot/'.urlencode($batchId);
        try {
            $ok = $this->postPayload($url, $headers, $timeout, $payload, true);
            return (bool) $ok;
        } catch (\Throwable $e) {
            $this->logger->error('BpMessage AddMessageToLot error: '.$e->getMessage());
            return false;
        }
    }

    private function finishLot(string $baseUrl, string $batchId, array $headers, int $timeout): bool
    {
        $url = rtrim($baseUrl,'/').'/api/Lot/FinishLot/'.urlencode($batchId);
        try {
            $ok = $this->postPayload($url, $headers, $timeout, [], true);
            return (bool) $ok;
        } catch (\Throwable $e) {
            $this->logger->error('BpMessage FinishLot error: '.$e->getMessage());
            return false;
        }
    }

    private function createEmailLot(string $baseUrl, array $headers, int $timeout, array $payload): ?string
    {
        $url = rtrim($baseUrl,'/').'/api/Email/CreateLot';
        try {
            $ok = $this->postPayload($url, $headers, $timeout, $payload, true);
            if (is_string($ok)) {
                $id = trim($ok);
                return $id !== '' ? $id : null;
            }
            if (!is_array($ok)) {
                return null;
            }
            return $this->extractIdFromResponse($ok) ?: ($ok['id'] ?? null);
        } catch (\Throwable $e) {
            $this->logger->error('BpMessage CreateEmailLot error: '.$e->getMessage());
            return null;
        }
    }

    private function addEmailToLot(string $baseUrl, string $batchId, array $payload, array $headers, int $timeout): bool
    {
        $url = rtrim($baseUrl,'/').'/api/Email/AddEmailToLot/'.urlencode($batchId);
        try {
            $ok = $this->postPayload($url, $headers, $timeout, $payload, true);
            return (bool) $ok;
        } catch (\Throwable $e) {
            $this->logger->error('BpMessage AddEmailToLot error: '.$e->getMessage());
            return false;
        }
    }

    private function finishEmailLot(string $baseUrl, string $batchId, array $headers, int $timeout): bool
    {
        $url = rtrim($baseUrl,'/').'/api/Email/FinishLot/'.urlencode($batchId);
        try {
            $ok = $this->postPayload($url, $headers, $timeout, [], true);
            return (bool) $ok;
        } catch (\Throwable $e) {
            $this->logger->error('BpMessage FinishEmailLot error: '.$e->getMessage());
            return false;
        }
    }

    private function postPayload(string $url, array $headers, int $timeout, array $payload, bool $returnJson = false)
    {
        $options = [
            \GuzzleHttp\RequestOptions::HEADERS => $headers,
            \GuzzleHttp\RequestOptions::TIMEOUT => $timeout,
            \GuzzleHttp\RequestOptions::JSON    => $payload,
        ];
        $response = $this->httpClient->post($url, $options);
        $code = $response->getStatusCode();
        $body = (string) $response->getBody();
        $this->logger->info('BpMessage POST', ['url' => $url, 'status' => $code, 'bodyPreview' => $this->stringPreview($body)]);
        if ($code >= 200 && $code < 300) {
            if ($returnJson) {
                $data = json_decode($body, true);
                if (is_array($data)) {
                    return $data;
                }
                // Retorna corpo bruto quando não é JSON (ex.: ID simples em texto)
                return trim($body);
            }
            return true;
        }
        return false;
    }

    private function sanitizeHeaders(mixed $headers): array
    {
        // Aceita array associativo ou lista de pares [ ['key' => k, 'value' => v] ]
        $result = [];
        if (is_array($headers)) {
            $assoc = [];
            foreach ($headers as $k => $v) {
                if (is_array($v) && isset($v['key'])) {
                    $key = (string) $v['key'];
                    $raw = $v['value'] ?? '';
                    if (is_array($raw) || is_object($raw)) {
                        $val = json_encode($raw);
                    } else {
                        $val = (string) $raw;
                    }
                } else {
                    $key = is_string($k) ? $k : '';
                    if (is_array($v)) {
                        // Tenta extrair 'value' comum de SortableListType; caso contrário serializa
                        if (array_key_exists('value', $v)) {
                            $raw = $v['value'];
                            if (is_array($raw) || is_object($raw)) {
                                $val = json_encode($raw);
                            } else {
                                $val = (string) $raw;
                            }
                        } else {
                            $val = json_encode($v);
                        }
                    } else {
                        $val = (string) $v;
                    }
                }
                if ($key === '') { continue; }
                $assoc[$key] = $val;
            }
            $result = $assoc;
        }
        return $result;
    }

    private function deleteProcessed(array $selected): int
    {
        $ids = [];
        foreach ($selected as $q) {
            if ($q instanceof BpMessageQueue && $q->getId() !== null) {
                $ids[] = $q->getId();
            }
        }
        /** @var BpMessageQueueRepository $repo */
        $repo = $this->em->getRepository(BpMessageQueue::class);
        $repo->deleteQueuesById($ids);
        return count($ids);
    }

    private function handleFailedBatch(array $selected, int $retryLimit, string $error): array
    {
        $scheduled = 0;
        $deleted = 0;
        foreach ($selected as $q) {
            if (!$q instanceof BpMessageQueue) { continue; }
            $tries = (int) $q->getRetries();
            // Política de retry: permitir ao menos uma nova tentativa quando retryLimit=1
            // Deleta apenas quando o número de tentativas já realizadas atinge o limite
            if ($tries >= $retryLimit) {
                $deleted += $this->deleteProcessed([$q]);
            } else {
                $q->setRetries($tries + 1);
                $q->setDateModified(new \DateTimeImmutable());
                $this->em->persist($q);
                $scheduled++;
            }
        }
        $this->em->flush();
        return ['scheduled' => $scheduled, 'deleted' => $deleted];
    }

    private function buildStaticHeaders(array $config): array
    {
        return $this->sanitizeHeaders($config['headers'] ?? []);
    }

    private function decodeConfig(BpMessageQueue $q): array
    {
        $raw = $q->getConfigJson();
        if (!is_string($raw) || $raw === '') { return []; }
        $conf = json_decode($raw, true);
        return is_array($conf) ? $conf : [];
    }

    private function decodePayload($payload): array
    {
        if (is_resource($payload)) {
            $payload = stream_get_contents($payload) ?: '';
        }
        if (!is_string($payload) || $payload === '') { return []; }
        $data = json_decode($payload, true);
        return is_array($data) ? $data : [];
    }

    private function stringPreview(string $s, int $max = 120): string
    {
        $s = trim($s);
        if (strlen($s) <= $max) { return $s; }
        return substr($s, 0, $max).'...';
    }

    private function extractIdFromResponse(array $resp): ?string
    {
        // Tenta encontrar id em campos comuns
        foreach (['idLot','idLotEmail','id','lotId','idLote'] as $k) {
            if (isset($resp[$k]) && is_scalar($resp[$k])) {
                $id = (string) $resp[$k];
                if ($id !== '') { return $id; }
            }
        }
        return null;
    }
}