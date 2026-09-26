<?php

namespace justinholtweb\erpynetsuite\connectors;

use Craft;
use DateTime;
use DateTimeInterface;
use justinholtweb\erpy\auth\OAuth1Tba;
use justinholtweb\erpy\base\AuthInterface;
use justinholtweb\erpy\base\Capabilities;
use justinholtweb\erpy\base\Connector;
use justinholtweb\erpy\base\Direction;
use justinholtweb\erpy\base\Entity;
use justinholtweb\erpy\base\FetchCriteria;
use justinholtweb\erpy\base\Field;
use justinholtweb\erpy\base\HealthResult;
use justinholtweb\erpy\base\Page;
use justinholtweb\erpy\base\PushResult;
use justinholtweb\erpy\base\Response;
use justinholtweb\erpy\base\Transport;
use justinholtweb\erpy\models\canonical\ErpAddress;
use justinholtweb\erpy\models\canonical\ErpCredit;
use justinholtweb\erpy\models\canonical\ErpCustomer;
use justinholtweb\erpy\models\canonical\ErpInvoice;
use justinholtweb\erpy\models\canonical\ErpOrder;
use justinholtweb\erpy\models\canonical\ErpOrderStatus;
use justinholtweb\erpy\models\canonical\ErpPrice;
use justinholtweb\erpy\models\canonical\ErpProduct;
use justinholtweb\erpy\models\canonical\ErpShipment;
use justinholtweb\erpy\models\canonical\ErpStock;

/**
 * Oracle NetSuite, through SuiteTalk REST and SuiteQL.
 *
 * NetSuite is the awkward one, in three specific ways this connector exists to absorb.
 *
 * It still signs every request with OAuth 1.0a — Token-Based Authentication — and answers a
 * mis-signed request with an opaque `INVALID_LOGIN_ATTEMPT` that says nothing about which of the
 * five credentials or four encoding rules went wrong.
 *
 * Its REST record API returns links rather than records: a list request gives you ids and you
 * fetch each one. That is fine for orders and ruinous for a 40,000-item catalogue, so everything
 * read in bulk here goes through **SuiteQL** instead, which returns whole rows in pages of a
 * thousand.
 *
 * And its account id is spelled two ways: `1234567_SB1` in the UI and in the OAuth realm, and
 * `1234567-sb1` — lower case, hyphenated — in every host name. Both are derived from the one
 * setting here, which accepts either spelling.
 *
 * Two further choices are deliberate. Nothing here reads a custom field unless the merchant names
 * one in settings: SuiteQL refuses an entire query over a single unknown column, so a field this
 * connector invented would fail the first sync on every account that had not created it. Order
 * statuses are matched by `externalId`, which the push itself sets. And stock is always read in
 * full, because NetSuite keeps no modified date for a stock level — see `fetchInventory()`.
 */
class NetSuiteConnector extends Connector
{
    /** SuiteQL's own maximum page size. Asking for more is silently capped. */
    private const SUITEQL_MAX = 1000;

    /**
     * Internal ids looked up by natural key, memoized for the life of the run: a 200-line order
     * would otherwise ask NetSuite for the same item over and over.
     *
     * @var array<string,string|null>
     */
    private array $idCache = [];

    public static function handle(): string
    {
        return 'netsuite';
    }

    public static function displayName(): string
    {
        return 'NetSuite';
    }

    public static function vendor(): string
    {
        return 'Oracle';
    }

    public static function description(): string
    {
        return 'Oracle NetSuite through SuiteTalk REST, with bulk reads done in SuiteQL so a large catalogue does not take all night.';
    }

    public static function setupUrl(): ?string
    {
        return 'https://docs.oracle.com/en/cloud/saas/netsuite/ns-online-help/section_157771733782.html';
    }

    public static function capabilities(): Capabilities
    {
        return Capabilities::make()
            ->supports(Entity::CUSTOMER, Direction::PULL, delta: true, pageSize: self::SUITEQL_MAX)
            ->supports(Entity::PRODUCT, Direction::PULL, delta: true, pageSize: self::SUITEQL_MAX)
            ->supports(Entity::PRICE, Direction::PULL, delta: false, pageSize: self::SUITEQL_MAX)
            // No delta: a stock level has no modified date of its own. See fetchInventory().
            ->supports(Entity::INVENTORY, Direction::PULL, delta: false, pageSize: self::SUITEQL_MAX)
            ->supports(Entity::ORDER, Direction::PUSH)
            ->supports(Entity::ORDER_STATUS, Direction::PULL, delta: true, pageSize: self::SUITEQL_MAX)
            ->supports(Entity::SHIPMENT, Direction::PULL, delta: true, pageSize: self::SUITEQL_MAX)
            ->supports(Entity::INVOICE, Direction::PULL, delta: true, pageSize: self::SUITEQL_MAX)
            ->supports(Entity::CREDIT, Direction::PULL, pageSize: self::SUITEQL_MAX)
            ->withMultiCompany()
            ->withSandbox();
    }

    public static function settingsFields(): array
    {
        return [
            Field::heading(
                Craft::t('erpy', 'Account'),
                Craft::t('erpy', 'Setup → Company → Company Information shows your account id. A sandbox looks like 1234567_SB1.'),
            ),
            Field::text('accountId', Craft::t('erpy', 'Account ID'), [
                'required' => true,
                'placeholder' => '1234567',
            ]),

            Field::heading(
                Craft::t('erpy', 'Token-Based Authentication'),
                Craft::t('erpy', 'Create an integration record under Setup → Integration → Manage Integrations with Token-Based Authentication enabled, then create an access token for it under Setup → Users/Roles → Access Tokens. NetSuite shows each secret once and never again.'),
            ),
            Field::secret('consumerKey', Craft::t('erpy', 'Consumer key'), ['required' => true]),
            Field::secret('consumerSecret', Craft::t('erpy', 'Consumer secret'), ['required' => true]),
            Field::secret('tokenId', Craft::t('erpy', 'Token ID'), ['required' => true]),
            Field::secret('tokenSecret', Craft::t('erpy', 'Token secret'), ['required' => true]),

            Field::heading(Craft::t('erpy', 'Behaviour')),
            Field::text('subsidiaryId', Craft::t('erpy', 'Subsidiary internal ID'), [
                'instructions' => Craft::t('erpy', 'Required on OneWorld accounts. Leave blank on a single-subsidiary account.'),
            ]),
            Field::text('locationId', Craft::t('erpy', 'Location internal ID'), [
                'instructions' => Craft::t('erpy', 'The location orders are raised against, and the one stock is read from.'),
            ]),
            Field::text('itemTypes', Craft::t('erpy', 'Item types to sync'), [
                'default' => 'InvtPart,NonInvtPart,Assembly,Kit',
                'instructions' => Craft::t('erpy', 'NetSuite item types, comma separated. Service and other non-sellable types are usually best left out.'),
            ]),
            Field::text('categoryField', Craft::t('erpy', 'Item category field'), [
                'placeholder' => 'custitem_web_category',
                'instructions' => Craft::t('erpy', 'The id of a custom item field holding the product category, if you have one. Leave blank and no category is read — NetSuite refuses a whole query that names a field the account does not have.'),
            ]),
            Field::text('orderReferenceField', Craft::t('erpy', 'Order reference field'), [
                'placeholder' => 'custbody_web_order_number',
                'instructions' => Craft::t('erpy', 'Orders Erpy creates are matched by their External ID. Only name a custom transaction body field here if another integration also raises web orders and keeps the Commerce order number in it.'),
            ]),
            Field::boolean('orderApproved', Craft::t('erpy', 'Create orders already approved'), [
                'instructions' => Craft::t('erpy', 'Off means orders land pending approval, which is what most finance teams want to start with.'),
                'default' => false,
            ]),
        ];
    }

    protected function buildAuth(): ?AuthInterface
    {
        return new OAuth1Tba();
    }

    protected function buildTransport(): Transport
    {
        // The host name spells the account id in lower case with hyphens; the OAuth realm spells
        // it in upper case with underscores. Both come from the one setting.
        $host = strtolower(str_replace('_', '-', (string)$this->setting('accountId')));

        return (new Transport())
            ->setBaseUri("https://$host.suitetalk.api.netsuite.com/services/rest")
            ->setDefaultHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
            ->setRateLimit(5)
            ->setTimeout(120);
    }

    protected function probe(): HealthResult
    {
        $response = $this->suiteQl('SELECT COUNT(*) AS total FROM item', 1, 0);

        if (!$response->ok()) {
            return HealthResult::fail($response->errorMessage(), $this->hintsFor($response));
        }

        $items = $response->at('items.0.total', '0');
        $company = $this->transport()->get('record/v1/subsidiary', ['limit' => 1]);

        return HealthResult::pass(
            Craft::t('erpy', 'Connected to NetSuite.'),
            [
                Craft::t('erpy', 'Account') => (string)$this->setting('accountId'),
                Craft::t('erpy', 'Items visible') => (string)$items,
                Craft::t('erpy', 'Subsidiaries readable') => $company->ok() ? Craft::t('erpy', 'yes') : Craft::t('erpy', 'no — check the role’s permissions'),
            ],
        );
    }

    private function hintsFor(Response $response): array
    {
        $body = $response->body;

        if (str_contains($body, 'INVALID_LOGIN_ATTEMPT') || $response->status === 401) {
            return [
                Craft::t('erpy', 'NetSuite says the same thing for every signing problem. Check, in this order: the account id (including any _SB1 suffix), that all four token values were pasted without stray whitespace, and that the integration record has Token-Based Authentication ticked.'),
                Craft::t('erpy', 'If the token was created for a different role, it can authenticate and still see nothing.'),
            ];
        }

        if ($response->status === 403) {
            return [Craft::t('erpy', 'The token works but the role is missing permissions. SuiteAnalytics Workbook is needed for SuiteQL, plus REST Web Services and Log in using Access Tokens.')];
        }

        return [];
    }

    // ---------------------------------------------------------------------------------------
    // Pull
    // ---------------------------------------------------------------------------------------

    protected function fetchProducts(FetchCriteria $criteria): Page
    {
        $types = $this->quotedList((string)$this->setting('itemTypes', 'InvtPart,NonInvtPart,Assembly,Kit'));

        $category = $this->customField('categoryField', 'custitem');
        $categoryColumn = $category !== null ? ", i.$category AS category" : '';

        $sql = "SELECT i.id, i.itemid, i.displayname, i.salesdescription, i.itemtype, i.isinactive,
                       i.upccode, i.baseunit, i.weight, i.weightunit, i.lastmodifieddate$categoryColumn
                FROM item i
                WHERE i.itemtype IN ($types)";

        return $this->queryPage($sql, 'i.lastmodifieddate', $criteria, Entity::PRODUCT, function(array $row): ErpProduct {
            return new ErpProduct([
                'sku' => (string)($row['itemid'] ?? ''),
                'name' => (string)($row['displayname'] ?: $row['itemid'] ?? ''),
                'description' => $row['salesdescription'] ?: null,
                'enabled' => !$this->truthy($row['isinactive'] ?? 'F'),
                'blocked' => $this->truthy($row['isinactive'] ?? 'F'),
                'category' => $row['category'] ?? null,
                'unitOfMeasure' => $row['baseunit'] ?? null,
                'barcode' => $row['upccode'] ?: null,
                'weight' => isset($row['weight']) ? (float)$row['weight'] : null,
                'weightUnit' => $row['weightunit'] ?? null,
                // Only inventory items and assemblies carry stock; the rest are sold without it.
                'tracksInventory' => in_array((string)($row['itemtype'] ?? ''), ['InvtPart', 'Assembly'], true),
                'remoteId' => (string)($row['id'] ?? ''),
                'remoteKey' => (string)($row['itemid'] ?? ''),
                'modifiedAt' => $this->date($row['lastmodifieddate'] ?? null),
                'raw' => $row,
            ]);
        });
    }

    protected function fetchInventory(FetchCriteria $criteria): Page
    {
        $location = (string)$this->setting('locationId', '');
        $where = $location !== '' ? "AND il.location = " . (int)$location : '';

        // Always a full read. NetSuite keeps no modified date for a stock level: a receipt, a
        // fulfilment or an adjustment changes `inventoryitemlocations` without touching the
        // item's `lastmodifieddate`, and neither that table nor `inventorybalance` has a date of
        // its own. Inferring changes from transactions' modified dates misses a deleted receipt
        // and a commitment released by a closed order, so it is not attempted: a slower sync
        // costs pages of a thousand rows, a missed movement costs a wrong stock figure.
        //
        // `quantityavailable` is NetSuite's own availability figure — on hand less what is
        // already committed to somebody else's order. It is the number worth showing a customer,
        // and computing it ourselves from on-hand would overstate stock on every busy SKU.
        $sql = "SELECT i.itemid, i.id, il.quantityonhand, il.quantityavailable,
                       il.quantitybackordered, il.quantityonorder, il.location
                FROM item i
                INNER JOIN inventoryitemlocations il ON il.item = i.id
                WHERE 1 = 1 $where";

        return $this->queryPage($sql, null, $criteria, Entity::INVENTORY, function(array $row): ErpStock {
            return new ErpStock([
                'sku' => (string)($row['itemid'] ?? ''),
                'warehouse' => isset($row['location']) ? (string)$row['location'] : null,
                'onHand' => (float)($row['quantityonhand'] ?? 0),
                'available' => isset($row['quantityavailable']) ? (float)$row['quantityavailable'] : null,
                'incoming' => isset($row['quantityonorder']) ? (float)$row['quantityonorder'] : null,
                'remoteId' => (string)($row['id'] ?? ''),
                'raw' => $row,
            ]);
        });
    }

    protected function fetchPrices(FetchCriteria $criteria): Page
    {
        // NetSuite's price matrix: one row per item, price level and quantity break. This is
        // exactly the shape Erpy's contract pricing wants, which is a rare piece of luck.
        $sql = "SELECT ip.item, i.itemid, ip.pricelevel, pl.name AS pricelevelname,
                       ip.quantity, ip.price, ip.currency
                FROM itemprice ip
                INNER JOIN item i ON i.id = ip.item
                LEFT JOIN pricelevel pl ON pl.id = ip.pricelevel
                WHERE 1 = 1";

        return $this->queryPage($sql, null, $criteria, Entity::PRICE, function(array $row): ErpPrice {
            return new ErpPrice([
                'sku' => (string)($row['itemid'] ?? ''),
                'priceListCode' => (string)($row['pricelevelname'] ?: $row['pricelevel'] ?? ''),
                'currency' => $row['currency'] ?? null,
                'unitPrice' => (float)($row['price'] ?? 0),
                'minQuantity' => (float)($row['quantity'] ?? 1) ?: 1.0,
                'remoteId' => (string)($row['item'] ?? '') . ':' . (string)($row['pricelevel'] ?? '') . ':' . (string)($row['quantity'] ?? 0),
                'raw' => $row,
            ]);
        });
    }

    protected function fetchCustomers(FetchCriteria $criteria): Page
    {
        $sql = "SELECT c.id, c.entityid, c.companyname, c.email, c.phone, c.isinactive,
                       c.creditlimit, c.balancesearch AS balance, c.currency, c.terms,
                       c.pricelevel, c.category, c.creditholdoverride, c.lastmodifieddate,
                       c.defaultbillingaddress, c.defaultshippingaddress
                FROM customer c
                WHERE 1 = 1";

        return $this->queryPage($sql, 'c.lastmodifieddate', $criteria, Entity::CUSTOMER, function(array $row): ErpCustomer {
            return new ErpCustomer([
                'code' => (string)($row['entityid'] ?? ''),
                'name' => (string)($row['companyname'] ?: $row['entityid'] ?? ''),
                'email' => $row['email'] ?: null,
                'phone' => $row['phone'] ?: null,
                'enabled' => !$this->truthy($row['isinactive'] ?? 'F'),
                // NetSuite's credit hold override is `AUTO`, `ON` or `OFF`; only `ON` is a stop.
                'onHold' => strtoupper((string)($row['creditholdoverride'] ?? '')) === 'ON',
                'currency' => isset($row['currency']) ? (string)$row['currency'] : null,
                'priceListCode' => isset($row['pricelevel']) ? (string)$row['pricelevel'] : null,
                'customerGroupCode' => isset($row['category']) ? (string)$row['category'] : null,
                'paymentTermsCode' => isset($row['terms']) ? (string)$row['terms'] : null,
                'creditLimit' => isset($row['creditlimit']) ? (float)$row['creditlimit'] : null,
                'balance' => isset($row['balance']) ? (float)$row['balance'] : null,
                'remoteId' => (string)($row['id'] ?? ''),
                'remoteKey' => (string)($row['entityid'] ?? ''),
                'modifiedAt' => $this->date($row['lastmodifieddate'] ?? null),
                'raw' => $row,
            ]);
        });
    }

    protected function fetchCredit(FetchCriteria $criteria): Page
    {
        $sql = "SELECT c.entityid, c.creditlimit, c.balancesearch AS balance,
                       c.unbilledorders, c.overduebalancesearch AS overdue, c.currency,
                       c.creditholdoverride
                FROM customer c
                WHERE c.isinactive = 'F'";

        return $this->queryPage($sql, null, $criteria, Entity::CREDIT, function(array $row): ErpCredit {
            return new ErpCredit([
                'customerCode' => (string)($row['entityid'] ?? ''),
                'creditLimit' => isset($row['creditlimit']) ? (float)$row['creditlimit'] : null,
                'balance' => (float)($row['balance'] ?? 0),
                // Unbilled orders are shipped-but-not-invoiced; NetSuite counts them against the
                // limit, and a storefront that does not will let a customer overrun it.
                'openOrders' => (float)($row['unbilledorders'] ?? 0),
                'overdueAmount' => (float)($row['overdue'] ?? 0),
                'onHold' => strtoupper((string)($row['creditholdoverride'] ?? '')) === 'ON',
                'raw' => $row,
            ]);
        });
    }

    protected function fetchOrderStatuses(FetchCriteria $criteria): Page
    {
        // `externalid` is what the push sets and what matches a status back to its Commerce order.
        // A custom reference field is read only when the merchant has named one.
        $reference = $this->customField('orderReferenceField', 'custbody');
        $referenceColumn = $reference !== null ? ", t.$reference AS reference" : '';

        $sql = "SELECT t.id, t.tranid, t.otherrefnum, t.status, t.lastmodifieddate, t.externalid$referenceColumn
                FROM transaction t
                WHERE t.type = 'SalesOrd'";

        return $this->queryPage($sql, 't.lastmodifieddate', $criteria, Entity::ORDER_STATUS, function(array $row): ErpOrderStatus {
            // NetSuite's sales order statuses: A pending approval, B pending fulfilment,
            // C cancelled, D partially fulfilled, E pending billing/partly fulfilled,
            // F pending billing, G billed, H closed.
            $status = strtoupper((string)($row['status'] ?? ''));

            return new ErpOrderStatus([
                'orderNumber' => (string)(($row['externalid'] ?? null) ?: ($row['reference'] ?? null) ?: ($row['otherrefnum'] ?? '')),
                'status' => $status,
                'statusCode' => $status,
                'isOnHold' => $status === 'A',
                'isPicking' => $status === 'B',
                'isCancelled' => $status === 'C',
                'isPartiallyShipped' => in_array($status, ['D', 'E'], true),
                'isShipped' => in_array($status, ['F', 'G', 'H'], true),
                'isInvoiced' => in_array($status, ['G', 'H'], true),
                'isClosed' => $status === 'H',
                'remoteId' => (string)($row['id'] ?? ''),
                'remoteKey' => (string)($row['tranid'] ?? ''),
                'modifiedAt' => $this->date($row['lastmodifieddate'] ?? null),
                'raw' => $row,
            ]);
        });
    }

    protected function fetchShipments(FetchCriteria $criteria): Page
    {
        $sql = "SELECT f.id, f.tranid, f.trandate, f.lastmodifieddate,
                       f.createdfrom, o.externalid AS orderexternalid, o.otherrefnum,
                       f.shipcarrier, f.linkedtrackingnumbers AS tracking
                FROM transaction f
                LEFT JOIN transaction o ON o.id = f.createdfrom
                WHERE f.type = 'ItemShip'";

        return $this->queryPage($sql, 'f.lastmodifieddate', $criteria, Entity::SHIPMENT, function(array $row): ErpShipment {
            return new ErpShipment([
                'orderNumber' => (string)($row['orderexternalid'] ?: $row['otherrefnum'] ?? ''),
                'shipmentNumber' => (string)($row['tranid'] ?? ''),
                'trackingNumber' => $this->firstTrackingNumber($row['tracking'] ?? null),
                'carrier' => $row['shipcarrier'] ?? null,
                'shippedAt' => $this->date($row['trandate'] ?? null),
                'remoteId' => (string)($row['id'] ?? ''),
                'modifiedAt' => $this->date($row['lastmodifieddate'] ?? null),
                'raw' => $row,
            ]);
        });
    }

    protected function fetchInvoices(FetchCriteria $criteria): Page
    {
        $sql = "SELECT t.id, t.tranid, t.trandate, t.duedate, t.foreigntotal, t.foreignamountunpaid,
                       t.currency, t.status, t.lastmodifieddate, t.createdfrom,
                       o.externalid AS orderexternalid, c.entityid AS customercode
                FROM transaction t
                LEFT JOIN transaction o ON o.id = t.createdfrom
                LEFT JOIN customer c ON c.id = t.entity
                WHERE t.type = 'CustInvc'";

        return $this->queryPage($sql, 't.lastmodifieddate', $criteria, Entity::INVOICE, function(array $row): ErpInvoice {
            $total = (float)($row['foreigntotal'] ?? 0);
            $unpaid = (float)($row['foreignamountunpaid'] ?? 0);

            return new ErpInvoice([
                'invoiceNumber' => (string)($row['tranid'] ?? ''),
                'orderNumber' => (string)($row['orderexternalid'] ?? ''),
                'customerCode' => (string)($row['customercode'] ?? ''),
                'issuedAt' => $this->date($row['trandate'] ?? null),
                'dueAt' => $this->date($row['duedate'] ?? null),
                'currency' => (string)($row['currency'] ?: 'USD'),
                'total' => $total,
                'balance' => $unpaid,
                'amountPaid' => $total - $unpaid,
                'isPaid' => abs($unpaid) < 0.005,
                // NetSuite signs credit memos as their own transaction type; a negative invoice
                // total here means a return authorisation was billed against the invoice.
                'isCreditNote' => $total < 0,
                'status' => (string)($row['status'] ?? ''),
                'remoteId' => (string)($row['id'] ?? ''),
                'modifiedAt' => $this->date($row['lastmodifieddate'] ?? null),
                'raw' => $row,
            ]);
        });
    }

    // ---------------------------------------------------------------------------------------
    // Push
    // ---------------------------------------------------------------------------------------

    protected function pushOrder(ErpOrder $document, ?string $remoteId = null): PushResult
    {
        if ($document->customerCode === null || $document->customerCode === '') {
            return PushResult::rejected(Craft::t('erpy', 'NetSuite needs a customer. Set a guest customer code on the order mapping, or link this customer to a NetSuite account.'));
        }

        $customerId = $this->lookupId('customer', 'entityid', $document->customerCode);

        if ($customerId === null) {
            return PushResult::rejected(Craft::t('erpy', 'No NetSuite customer with the id “{code}”.', ['code' => $document->customerCode]));
        }

        // NetSuite's `externalId` is a genuine idempotency key: a second create with the same one
        // is rejected rather than duplicated. That is a much stronger guarantee than asking
        // first, and it holds even if two workers get past every other guard.
        $payload = [
            'externalId' => $document->orderNumber,
            'entity' => ['id' => $customerId],
            'tranDate' => ($document->orderedAt ?? new DateTime())->format('Y-m-d'),
            'otherRefNum' => mb_substr($document->orderNumber, 0, 45),
            'currency' => $document->currency ? ['refName' => $document->currency] : null,
            'memo' => $document->customerNote,
            'orderStatus' => ['id' => $this->boolSetting('orderApproved') ? 'B' : 'A'],
            'item' => ['items' => []],
        ];

        if ($subsidiary = (string)$this->setting('subsidiaryId', '')) {
            $payload['subsidiary'] = ['id' => $subsidiary];
        }

        if ($location = (string)$this->setting('locationId', '')) {
            $payload['location'] = ['id' => $location];
        }

        foreach ($document->lines as $line) {
            $itemId = $this->lookupId('item', 'itemid', $line->sku);

            if ($itemId === null) {
                return PushResult::rejected(Craft::t('erpy', 'No NetSuite item with the id “{sku}”.', ['sku' => $line->sku]));
            }

            $payload['item']['items'][] = array_filter([
                'item' => ['id' => $itemId],
                'quantity' => $line->quantity,
                'rate' => $line->unitPrice,
                'description' => $line->description,
            ], static fn($value) => $value !== null && $value !== '');
        }

        if ($document->shippingAddress && !$document->shippingAddress->isEmpty()) {
            $payload['shippingAddress'] = $this->address($document->shippingAddress);
        }

        if ($document->billingAddress && !$document->billingAddress->isEmpty()) {
            $payload['billingAddress'] = $this->address($document->billingAddress);
        }

        foreach ($document->customFields as $field => $value) {
            $payload[$field] = $value;
        }

        $response = $this->transport()->post('record/v1/salesOrder', array_filter(
            $payload,
            static fn($value) => $value !== null,
        ));

        if ($response->status === 204 || $response->ok()) {
            // NetSuite answers a create with 204 and the new record's URL in the Location header
            // rather than a body — the id is the last path segment.
            $location = $response->header('Location');
            $id = $location ? (string)substr(strrchr($location, '/') ?: '', 1) : '';

            return $id !== ''
                ? PushResult::ok($id, $document->orderNumber)
                : PushResult::failed(Craft::t('erpy', 'NetSuite accepted the order but returned no id.'));
        }

        // A duplicate external id means a previous attempt got through and we never heard back.
        // That is a success from the merchant's point of view, not a failure.
        if (str_contains($response->body, 'DUP_EXTERNAL_ID') || str_contains($response->body, 'externalId')) {
            $existing = $this->lookupId('transaction', 'externalid', $document->orderNumber, "type = 'SalesOrd'");

            if ($existing !== null) {
                return PushResult::alreadyExists($existing, $document->orderNumber);
            }
        }

        return $response->status >= 400 && $response->status < 500
            ? PushResult::rejected($response->errorMessage(), $response->json_())
            : PushResult::failed($response->errorMessage(), $response->json_());
    }

    private function address($address): array
    {
        return array_filter([
            'addressee' => $address->fullName,
            'attention' => $address->organization,
            'addr1' => $address->addressLine1,
            'addr2' => $address->addressLine2,
            'city' => $address->locality,
            'state' => $address->administrativeArea,
            'zip' => $address->postalCode,
            'country' => $address->countryCode ? ['id' => $address->countryCode] : null,
        ], static fn($value) => $value !== null && $value !== '');
    }

    // ---------------------------------------------------------------------------------------
    // SuiteQL
    // ---------------------------------------------------------------------------------------

    /**
     * One page of a SuiteQL query, turned into canonical documents.
     *
     * SuiteQL pages by offset rather than by cursor, so the cursor here is the offset — which is
     * why the engine's "did the connector repeat a cursor?" guard is worth having.
     */
    private function queryPage(string $sql, ?string $deltaColumn, FetchCriteria $criteria, string $entity, callable $make): Page
    {
        $limit = min($this->pageSize($entity, $criteria), self::SUITEQL_MAX);
        $offset = (int)($criteria->cursor ?? 0);

        if ($deltaColumn !== null && $criteria->since instanceof DateTimeInterface) {
            // SuiteQL wants an Oracle date literal, and TO_DATE with an explicit format is the
            // only spelling that behaves the same in every account's locale.
            $sql .= sprintf(
                " AND %s >= TO_DATE('%s', 'YYYY-MM-DD HH24:MI:SS')",
                $deltaColumn,
                $criteria->since->format('Y-m-d H:i:s'),
            );
        }

        $response = $this->suiteQl($sql, $limit, $offset);

        if (!$response->ok()) {
            throw new \RuntimeException('NetSuite refused the query: ' . $response->errorMessage());
        }

        $items = [];

        foreach ($response->at('items', []) as $row) {
            if (is_array($row)) {
                $items[] = $make($this->lowerKeys($row));
            }
        }

        $hasMore = (bool)$response->at('hasMore', false);

        return new Page($items, $hasMore ? (string)($offset + $limit) : null, $response->at('totalResults'));
    }

    private function suiteQl(string $sql, int $limit, int $offset): Response
    {
        return $this->transport()->request('POST', 'query/v1/suiteql', [
            'query' => ['limit' => $limit, 'offset' => $offset],
            'json' => ['q' => preg_replace('/\s+/', ' ', trim($sql))],
            // Without this header NetSuite answers every SuiteQL request with a 400 and a message
            // about an unsupported media type that mentions nothing about the header.
            'headers' => ['Prefer' => 'transient'],
        ]);
    }

    private function lookupId(string $table, string $column, string $value, string $extra = ''): ?string
    {
        $key = "$table:$column:$value:$extra";

        if (array_key_exists($key, $this->idCache)) {
            return $this->idCache[$key];
        }

        $where = $extra !== '' ? "AND $extra" : '';
        $sql = sprintf(
            "SELECT id FROM %s WHERE %s = '%s' %s",
            $table,
            $column,
            str_replace("'", "''", $value),
            $where,
        );

        $response = $this->suiteQl($sql, 1, 0);
        $id = $response->ok() ? ($response->at('items.0.id') ?? null) : null;

        return $this->idCache[$key] = $id !== null ? (string)$id : null;
    }

    // ---------------------------------------------------------------------------------------
    // Plumbing
    // ---------------------------------------------------------------------------------------

    /**
     * A custom field id the merchant named in settings, or null when they named none.
     *
     * It is interpolated into SuiteQL, so it has to look like a NetSuite script id — `custitem_…`
     * or `custbody_…` — and nothing else. Anything that does not is ignored with a warning rather
     * than sent, because the alternative is a query NetSuite refuses outright.
     */
    private function customField(string $setting, string $prefix): ?string
    {
        $id = strtolower(trim((string)$this->setting($setting, '')));

        if ($id === '') {
            return null;
        }

        if (!preg_match('/^' . $prefix . '[a-z0-9_]+$/', $id)) {
            Craft::warning(sprintf('Erpy NetSuite: ignored the %s setting, “%s” is not a %s… field id.', $setting, $id, $prefix), 'erpy');

            return null;
        }

        return $id;
    }

    /**
     * SuiteQL returns column names in whatever case the query used; normalising here means the
     * mapping above never has to guess.
     */
    private function lowerKeys(array $row): array
    {
        $out = [];

        foreach ($row as $key => $value) {
            $out[strtolower((string)$key)] = $value;
        }

        return $out;
    }

    private function quotedList(string $csv): string
    {
        $parts = array_filter(array_map('trim', explode(',', $csv)));

        return implode(', ', array_map(static fn(string $part) => "'" . str_replace("'", "''", $part) . "'", $parts))
            ?: "'InvtPart'";
    }

    /**
     * NetSuite answers booleans as `T` and `F`, and occasionally as `true`/`false` through REST.
     */
    private function truthy(mixed $value): bool
    {
        return in_array(strtoupper((string)$value), ['T', 'TRUE', '1', 'YES'], true);
    }

    /**
     * A fulfilment can carry several tracking numbers in one newline- or comma-separated field.
     * The first is the one worth showing a customer.
     */
    private function firstTrackingNumber(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $parts = preg_split('/[\r\n,;]+/', $value) ?: [];
        $first = trim((string)reset($parts));

        return $first !== '' ? $first : null;
    }

    private function date(mixed $value): ?DateTime
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new DateTime($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
