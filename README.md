# MauticBpMessageBundle

Implements four distinct campaign actions integrating with Bellinati Perez Message service, maintaining job-based execution, retries, and detailed logging.

## Actions

- Messages (batch): `campaign.bpmessage.messages_batch`
  - Endpoints: `GET /api/ServiceSettings/GetRoutes`, `POST /api/Lot/CreateLot`, `POST /api/Lot/AddMessageToLot/{idLot}`, `POST /api/Lot/FinishLot/{idLot}`
- Messages (single): `campaign.bpmessage.messages_single`
  - Endpoint: `POST /api/Message/AddMessageInvoice`
- Emails (batch): `campaign.bpmessage.emails_batch`
  - Endpoints: `GET /api/ServiceSettings/GetRoutes`, `POST /api/Email/CreateLot`, `POST /api/Email/AddEmailToLot/{idLotEmail}`, `POST /api/Email/FinishLot/{idLotEmail}`
- Emails (single): `campaign.bpmessage.emails_single`
  - Endpoint: `POST /api/Email/AddEmailInvoice`

## Configuration

- Base URL: set in Integration settings as `External service URL` (e.g. `https://hmlbpmessage.bellinatiperez.com.br`).
- Default headers, payload template, batch size, batch interval, retry limit, timeout can be set in Integration settings and are merged into action config.

## Processing

- Requests execute via queue model `BpMessageModel` and entity `BpMessageQueue`.
- Batch actions aggregate payloads using `payload_key = data` and respect `batch_size` and `batch_interval`.
- Single actions send one request per queued contact.
- Retry policy increments `retries` up to `retry_limit`, then discards.
- Logs are emitted to the Mautic logger with detailed messages per step and HTTP status.

## Business Rules

- Hooks are prepared to apply business rules prior to sending (extend `BpMessageModel` methods to enrich payloads/headers according to `/BpMessage_EnvioTESTE3 1 1.pdf`).

## Backward Compatibility

- Legacy action `campaign.bpmessage` remains available and routes to the default messages batch flow.

## Notes

- Maintain existing folder structure and namespaces.
- Clear cache after enabling the plugin: `php bin/console cache:clear` (both dev and prod if applicable).