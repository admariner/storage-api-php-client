<?php

namespace Keboola\Test\Backend\MixedSnowflakeBigquery;

use DateTime;
use Generator;
use Google\Cloud\BigQuery\BigQueryClient;
use Google\Cloud\BigQuery\Table;
use Keboola\Csv\CsvFile;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Workspaces;
use Keboola\TableBackendUtils\Escaping\Bigquery\BigqueryQuote;
use Keboola\Test\Backend\Mixed\StorageApiSharingTestCase;
use Keboola\Test\Backend\WorkspaceConnectionTrait;
use Keboola\Test\Backend\Workspaces\Backend\BigqueryWorkspaceBackend;
use Keboola\Test\Backend\Workspaces\Backend\WorkspaceBackendFactory;
use Keboola\Test\Utils\EventsQueryBuilder;
use Throwable;

class SharingTest extends StorageApiSharingTestCase
{
    use WorkspaceConnectionTrait;

    /**
     * @dataProvider sharingMethodProvider
     * @throws ClientException
     * @throws \Doctrine\DBAL\Exception
     * @throws \Keboola\StorageApi\Exception
     */
    public function testAccessDataInLinkedBucketFromWSViaROAndViewIM(string $shareMethod): void
    {
        //setup test tables
        $this->deleteAllWorkspaces();
        $this->initTestBuckets(self::BACKEND_BIGQUERY);
        $bucketId = $this->getTestBucketId();

        // create table in SHARING bucket in project A
        $this->_client->createTableAsync(
            $bucketId,
            'languagesFromClient1',
            new CsvFile(__DIR__ . '/../../_data/languages.csv'),
            [],
        );
        $this->shareByMethod($shareMethod, $bucketId);

        self::assertTrue($this->_client->isSharedBucket($bucketId));
        $response = $this->_client2->listSharedBuckets();
        self::assertCount(1, $response);
        $sharedBucket = reset($response);

        $linkedBucketName = 'linked-' . time();
        $linkedBucketId = $this->_client2->linkBucket(
            $linkedBucketName,
            'out',
            $sharedBucket['project']['id'],
            $sharedBucket['id'],
        );

        // create WS in project B
        $workspaces = new Workspaces($this->_client2);
        $workspace = $workspaces->createWorkspace(
            ['backend' => self::BACKEND_BIGQUERY],
            true,
        );

        /** @var BigQueryClient $wsDb */
        $wsDb = $this->getDbConnection($workspace['connection']);
        self::assertInstanceOf(BigQueryClient::class, $wsDb);

        $wsBackend = WorkspaceBackendFactory::createWorkspaceBackend($workspace);

        // create table in WS and insert some data
        $wsBackend->createTable('tableInWs', ['NAME' => 'STRING']);
        $wsBackend->executeQuery(sprintf(
            "INSERT INTO %s.%s VALUES ('pat')",
            BigqueryQuote::quoteSingleIdentifier($workspace['name']),
            BigqueryQuote::quoteSingleIdentifier('tableInWs'),
        ));
        $wsBackend->executeQuery(sprintf(
            "INSERT INTO %s.%s VALUES ('mat')",
            BigqueryQuote::quoteSingleIdentifier($workspace['name']),
            BigqueryQuote::quoteSingleIdentifier('tableInWs'),
        ));

        $dataset = $wsDb->datasets();
        // check there is two datasets available in workspace one is linked bucket
        // second is workspace self
        $this->assertCount(2, $dataset);

        // check is exist manually created table in workspaces
        $wsDataset = $wsDb->dataset($workspace['name']);
        $this->assertTrue($wsDataset->exists());
        $tableCreatedInWs = $wsDataset->table('tableInWs');
        $this->assertTrue($tableCreatedInWs->exists());

        // check can select from linked table
        $linkedBucketSchemaName = 'out_c_' . str_replace('-', '_', $linkedBucketName);
        $linkedDataset = $wsDb->dataset($linkedBucketSchemaName);
        $this->assertTrue($linkedDataset->exists());
        $tableInLinkedDataset = $linkedDataset->table('languagesFromClient1');
        $this->assertTrue($tableInLinkedDataset->exists());

        // check numbers of rows of table manually created in WS
        // and linked bucket
        $this->assertCount(2, $tableCreatedInWs->rows());
        $this->assertCount(5, $tableInLinkedDataset->rows());

        // load to workspace
        $tables = $this->_client2->listTables($linkedBucketId);
        $this->assertCount(1, $tables);
        $options = [
            'input' => [
                [
                    'source' => $tables[0]['id'],
                    'destination' => 'linked',
                    'useView' => true,
                ],
            ],
        ];
        $workspaces->loadWorkspaceData($workspace['id'], $options);
        $schemaRef = $wsBackend->getSchemaReflection();
        $views = $schemaRef->getViewsNames();
        $this->assertCount(1, $views);
        $this->assertContains('linked', $views);
        $this->assertCount(5, $wsBackend->fetchAll('linked'));
    }

    public function testAllSharingOperations(): void
    {
        //setup test tables
        $this->initTestBuckets(self::BACKEND_BIGQUERY);
        $bucketId = $this->getTestBucketId();

        // share and link bucket A->B
        $this->_client->shareOrganizationBucket($bucketId);
        self::assertTrue($this->_client->isSharedBucket($bucketId));
        $response = $this->_client2->listSharedBuckets();
        self::assertCount(1, $response);
        $sharedBucket = reset($response);

        // link
        /** @var string $linkedId */
        $linkedId = $this->_client2->linkBucket(
            'linked-' . time(),
            'out',
            $sharedBucket['project']['id'],
            $sharedBucket['id'],
        );

        // unlink
        $this->_client2->dropBucket($linkedId);
        self::assertEmpty($this->_client2->listBuckets(['include' => 'linkedBuckets']));

        // unshare
        $this->_client->unshareBucket($bucketId);
        self::assertFalse($this->_client->isSharedBucket($bucketId));

        $response = $this->_client2->listSharedBuckets();
        self::assertCount(0, $response);

        // share back
        $this->_client->shareOrganizationBucket($bucketId);
        self::assertTrue($this->_client->isSharedBucket($bucketId));

        // link again!
        $this->_client2->linkBucket(
            'linked-' . time(),
            'out',
            $sharedBucket['project']['id'],
            $sharedBucket['id'],
        );

        $linkedBuckets = $this->_client2->listBuckets(['include' => 'linkedBuckets']);
        $this->assertCount(1, $linkedBuckets);
        /** @var array $firstLinked */
        $firstLinked = reset($linkedBuckets);
        $this->assertEquals($bucketId, $firstLinked['sourceBucket']['id']);
    }

    public function testCreateTableInBucketWithDifferentBackendAsProjectDefault(): void
    {
        $this->initTestBuckets(self::BACKEND_SNOWFLAKE);
        $bucketId = $this->getTestBucketId();

        // create table in SHARING bucket in project A
        $tableId = $this->_client->createTableAsync(
            $bucketId,
            'languagesFromClient1',
            new CsvFile(__DIR__ . '/../../_data/languages.csv'),
            [],
        );

        $preview = $this->_client->getTableDataPreview($tableId);
        $this->assertCount(5, Client::parseCsv($preview));
    }

    public function testLinkedTableLoadAsView(): void
    {
        $this->deleteAllWorkspaces();
        $this->initTestBuckets(self::BACKEND_BIGQUERY);
        $bucketId = $this->getTestBucketId();
        $workspaces = new Workspaces($this->_client2);
        $workspace = $workspaces->createWorkspace([], true);
        $backend = WorkspaceBackendFactory::createWorkspaceBackend($workspace);
        assert($backend instanceof BigqueryWorkspaceBackend);

        // prepare table and alias table
        $importFile = __DIR__ . '/../../_data/languages.csv';
        $sourceTableId = $this->_client->createTableAsync(
            $bucketId,
            'languages',
            new CsvFile($importFile),
        );
        $this->_client->createAliasTable($bucketId, $sourceTableId, 'languages-alias');

        $targetProjectId = $this->_client2->verifyToken()['owner']['id'];
        $this->_client->shareBucketToProjects($bucketId, [$targetProjectId]);

        self::assertTrue($this->_client->isSharedBucket($bucketId));
        $response = $this->_client2->listSharedBuckets();
        self::assertCount(1, $response);
        $sharedBucket = reset($response);

        // link bucket to another project
        $linkedBucketName = 'linked-' . time();
        $linkedBucketId = $this->_client2->linkBucket(
            $linkedBucketName,
            'out',
            $sharedBucket['project']['id'],
            $sharedBucket['id'],
        );

        $storageTablesInDestProject = $this->_client2->listTables($linkedBucketId);
        $this->assertCount(2, $storageTablesInDestProject);
        $options = [
            'input' => [
                [
                    'source' => $storageTablesInDestProject[0]['id'],
                    'destination' => 'linked',
                    'useView' => true,
                ],
            ],
        ];
        // test load table to workspace as view is working
        $workspaces->loadWorkspaceData($workspace['id'], $options);

        $dataset = $backend->getDataset();
        $tables = iterator_to_array($dataset->tables());

        $this->assertCount(1, $tables);
        $this->assertSame('VIEW', $tables[0]->info()['type']);
        $this->assertCount(5, $backend->fetchAll('linked'));
        $this->assertColumns($dataset->table('linked'), ['id', 'name', '_timestamp']);

        // then load alias table in linked bucket as view should fail
        $options = [
            'input' => [
                [
                    'source' => $storageTablesInDestProject[1]['id'],
                    'destination' => 'linked-alias',
                    'useView' => true,
                ],
            ],
        ];

        try {
            $workspaces->loadWorkspaceData($workspace['id'], $options);
            $this->fail('Must throw exception as load alias as view is not supported');
        } catch (ClientException $e) {
            $this->assertSame('workspace.loadRequestLogicalException', $e->getStringCode());
            $this->assertSame(
                'View load is not supported, only table can be loaded using views, alias of table supplied. Use read-only storage instead or copy input mapping if supported.',
                $e->getMessage(),
            );
        }
    }

    public function testLinkBucketExport(): void
    {
        $this->deleteAllWorkspaces();
        $this->initTestBuckets(self::BACKEND_BIGQUERY);
        $bucketId = $this->getTestBucketId();

        $importFile = __DIR__ . '/../../_data/languages.csv';
        $this->_client->createTableAsync(
            $bucketId,
            'languages',
            new CsvFile($importFile),
        );

        $targetProjectId = $this->_client2->verifyToken()['owner']['id'];
        $this->_client->shareBucketToProjects($bucketId, [$targetProjectId]);

        self::assertTrue($this->_client->isSharedBucket($bucketId));
        $response = $this->_client2->listSharedBuckets();
        self::assertCount(1, $response);
        $sharedBucket = reset($response);

        // link bucket to another project
        $linkedBucketName = 'linked-' . time();
        $linkedBucketId = $this->_client2->linkBucket(
            $linkedBucketName,
            'out',
            $sharedBucket['project']['id'],
            $sharedBucket['id'],
        );

        $storageTablesInDestProject = $this->_client2->listTables($linkedBucketId);
        $this->assertCount(1, $storageTablesInDestProject);

        $this->_client2->exportTableAsync(
            $storageTablesInDestProject[0]['id'],
        );
    }

    public function testBucketShareUpdate(): void
    {
        $this->initEvents($this->_client);

        $bucketName = 'bucketShareTesting';

        $this->dropBucketIfExists($this->_client, "in.c-{$bucketName}", true);
        $bucketId = $this->_client->createBucket($bucketName, self::STAGE_IN);

        $bucket = $this->_client->getBucket($bucketId);
        $this->assertNull($bucket['updated']);

        $this->_client->shareOrganizationProjectBucket($bucketId);

        $eventAssertCallback = function ($events) use ($bucketId) {
            $this->assertCount(1, $events);

            $this->assertSame('bucket', $events[0]['objectType']);
            $this->assertSame($bucketId, $events[0]['objectId']);
        };

        $tokenData = $this->_client->verifyToken();
        $query = new EventsQueryBuilder();
        $query->setEvent('storage.bucketSharingEnabled')
            ->setTokenId($tokenData['id'])
            ->setObjectId($bucketId);
        $this->assertEventWithRetries($this->_client, $eventAssertCallback, $query);

        $bucket = $this->_client->getBucket($bucketId);
        $this->assertNotNull($bucket['updated']);

        $shareEnabledTime = $bucket['updated'];

        $this->_client->shareOrganizationBucket($bucketId);

        $query = new EventsQueryBuilder();
        $query->setEvent('storage.bucketSharingTypeChanged')
            ->setTokenId($tokenData['id'])
            ->setObjectId($bucketId);
        $this->assertEventWithRetries($this->_client, $eventAssertCallback, $query);

        $bucket = $this->_client->getBucket($bucketId);
        $this->assertNotNull($bucket['updated']);

        $shareTypeChangedTime = $bucket['updated'];

        $this->assertGreaterThan(
            DateTime::createFromFormat(DateTime::ATOM, $shareEnabledTime),
            DateTime::createFromFormat(DateTime::ATOM, $shareTypeChangedTime),
        );

        $this->_client->unshareBucket($bucketId);

        $query = new EventsQueryBuilder();
        $query->setEvent('storage.bucketSharingDisabled')
            ->setTokenId($tokenData['id'])
            ->setObjectId($bucketId);
        $this->assertEventWithRetries($this->_client, $eventAssertCallback, $query);

        $bucket = $this->_client->getBucket($bucketId);
        $this->assertNotNull($bucket['updated']);

        $shareDisabledTime = $bucket['updated'];

        $this->assertGreaterThan(
            DateTime::createFromFormat(DateTime::ATOM, $shareTypeChangedTime),
            DateTime::createFromFormat(DateTime::ATOM, $shareDisabledTime),
        );
    }

    private function assertColumns(Table $table, array $expectedColumns): void
    {
        $table->reload();
        $this->assertSame(
            $expectedColumns,
            array_map(fn(array $i) => $i['name'], $table->info()['schema']['fields']),
        );
    }
}
