<?php

declare(strict_types=1);

namespace Keboola\Test\Backend\ExternalBuckets;

use Exception;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\Workspaces;
use Keboola\TableBackendUtils\Escaping\Snowflake\SnowflakeQuote;
use Keboola\Test\Backend\WorkspaceConnectionTrait;
use Keboola\Test\Backend\Workspaces\Backend\WorkspaceBackendFactory;
use Keboola\Test\Utils\SnowflakeConnectionUtils;
use Keboola\Test\Utils\EventsQueryBuilder;
use Throwable;

class SnowflakeExternalBucketShareTest extends BaseExternalBuckets
{
    use WorkspaceConnectionTrait;
    use SnowflakeConnectionUtils;

    protected Client $shareClient;

    protected Client $linkingClient;

    public function setUp(): void
    {
        parent::setUp();
        $this->initEmptyTestBucketsForParallelTests();
        $this->shareClient = $this->getClientForToken(
            STORAGE_API_SHARE_TOKEN,
        );
        $this->linkingClient = $this->getClientForToken(
            STORAGE_API_LINKING_TOKEN,
        );

        $tokenData = $this->shareClient->verifyToken();
        if ($tokenData['organization']['id'] !== $this->linkingClient->verifyToken()['organization']['id']) {
            throw new \Exception('STORAGE_API_LINKING_TOKEN is not in the same organization as STORAGE_API_TOKEN');
        }
    }

    public function testExternalSchemaAsSharedBucket(): void
    {
        $bucketName = $this->getTestBucketName($this->generateDescriptionForTestObject());

        $this->forceUnshareBucketIfExists($this->shareClient, self::STAGE_IN . '.' . $bucketName, true);
        $this->dropBucketIfExists($this->_client, self::STAGE_IN . '.' . $bucketName, true);

        $this->initEvents($this->_client);
        $guide = $this->_client->registerBucketGuide([self::EXTERNAL_DB, self::EXTERNAL_SCHEMA], 'snowflake');

        $guideExploded = explode("\n", $guide['markdown']);
        $db = $this->ensureSnowflakeConnection();

        $this->prepareExternalFirstTable($db, $guideExploded);

        $registeredBucketId = $this->_client->registerBucket(
            $bucketName,
            [self::EXTERNAL_DB, self::EXTERNAL_SCHEMA],
            self::STAGE_IN,
            'will not fail',
            'snowflake',
            $bucketName . '-registered',
        );

        $tables = $this->_client->listTables(self::STAGE_IN . '.' . $bucketName);
        $this->assertCount(1, $tables);

        $shareToken = $this->linkingClient->verifyToken();
        $targetProjectId = $shareToken['owner']['id'];

        $this->shareClient->shareBucketToProjects($registeredBucketId, [$targetProjectId]);

        $sharedBucket = $this->_client->getBucket($registeredBucketId);
        $this->assertTrue($sharedBucket['hasExternalSchema']);
        $this->assertEquals('specific-projects', $sharedBucket['sharing']);
        $this->assertEquals($targetProjectId, $sharedBucket['sharingParameters']['projects'][0]['id']);

        $linkingWorkspaces = new Workspaces($this->linkingClient);
        $linkingWorkspace = $linkingWorkspaces->createWorkspace([], true);
        $linkingBackend = WorkspaceBackendFactory::createWorkspaceBackend($linkingWorkspace);

        /** @var \Keboola\Db\Import\Snowflake\Connection $linkingSnowflakeDb */
        $linkingSnowflakeDb = $linkingBackend->getDb();

        // check before link is not work via RO
        try {
            $linkingSnowflakeDb->fetchAll(sprintf(
                'SELECT * FROM %s.%s.%s',
                SnowflakeQuote::quoteSingleIdentifier(self::EXTERNAL_DB),
                SnowflakeQuote::quoteSingleIdentifier(self::EXTERNAL_SCHEMA),
                SnowflakeQuote::quoteSingleIdentifier(self::EXTERNAL_TABLE),
            ));
            $this->fail('Select should fail.');
        } catch (Throwable $e) {
            $this->assertStringContainsString('Database \'EXT_DB\' does not exist or not authorized., SQL state 02000 in SQLPrepare', $e->getMessage());
        }

        // LINKING START
        $this->dropBucketIfExists($this->linkingClient, self::STAGE_IN . '.' . $bucketName, true);

        $token = $this->_client->verifyToken();
        $linkedBucketId = $this->linkingClient->linkBucket(
            $bucketName,
            self::STAGE_IN,
            $token['owner']['id'],
            $sharedBucket['id'],
            $bucketName . '-linked',
        );
        $linkedBucket = $this->linkingClient->getBucket($linkedBucketId);
        $this->assertEquals($sharedBucket['id'], $linkedBucket['sourceBucket']['id']);
        $this->assertTrue($linkedBucket['hasExternalSchema']);
        $linkingTables = $this->linkingClient->listTables($linkedBucketId);
        $this->assertCount(1, $linkingTables);
        $linkingTable = $linkingTables[0];

        $dataPreview = $this->linkingClient->getTableDataPreview($linkingTable['id']);
        $this->assertEquals(
            <<<EXPECTED
"ID","LASTNAME"
"1","Novák"

EXPECTED,
            $dataPreview,
        );

        // test RO works
        /** @var \Keboola\Db\Import\Snowflake\Connection $linkingSnowflakeDb */
        $linkingSnowflakeDb = $linkingBackend->getDb();

        $result = $linkingSnowflakeDb->fetchAll(sprintf(
            'SELECT * FROM %s.%s.%s',
            SnowflakeQuote::quoteSingleIdentifier(self::EXTERNAL_DB),
            SnowflakeQuote::quoteSingleIdentifier(self::EXTERNAL_SCHEMA),
            SnowflakeQuote::quoteSingleIdentifier(self::EXTERNAL_TABLE),
        ));
        $this->assertEquals(
            [
                [
                    'ID' => 1,
                    'LASTNAME' => 'Novák',
                ],
            ],
            $result,
        );

        // REFRESH START

        $db->executeQuery(sprintf(
            'ALTER TABLE %s ADD COLUMN GENDER VARCHAR(50);',
            SnowflakeQuote::quoteSingleIdentifier(self::EXTERNAL_TABLE),
        ));

        $db->executeQuery(sprintf(
            'UPDATE %s SET GENDER = %s WHERE ID = 1;',
            SnowflakeQuote::quoteSingleIdentifier(self::EXTERNAL_TABLE),
            SnowflakeQuote::quote('male'),
        ));

        $db->executeQuery(sprintf(
            'CREATE TABLE %s (ID INT, DESC VARCHAR(255))',
            SnowflakeQuote::quoteSingleIdentifier(self::EXTERNAL_TABLE_2),
        ));
        $db->executeQuery(sprintf(
            'INSERT INTO %s (ID, DESC) VALUES (1, %s)',
            SnowflakeQuote::quoteSingleIdentifier(self::EXTERNAL_TABLE_2),
            SnowflakeQuote::quote('important description'),
        ));

        // in test we cannot use GRANT FUTURE, so we have to re-grant all changes to main role
        // rest re-grants should be done in `refreshBucket`
        foreach ($guideExploded as $command) {
            if (str_starts_with($command, 'GRANT') && !str_contains($command, 'FUTURE')) {
                try {
                    $db->executeQuery($command);
                } catch (Exception $e) {
                    $this->fail($e->getMessage() . ': ' . $command);
                }
            }
        }

        $this->_client->refreshBucket($registeredBucketId);

        $assertCallback = function ($events) {
            $this->assertCount(1, $events);
        };
        $query = new EventsQueryBuilder();
        $query->setEvent('storage.tableColumnsUpdated')
            ->setObjectId($linkedBucketId . '.' . self::EXTERNAL_TABLE)
            ->setTokenId($this->tokenId);
        $this->assertEventWithRetries($this->linkingClient, $assertCallback, $query);

        $assertCallback = function ($events) {
            $this->assertCount(1, $events);
        };
        $query = new EventsQueryBuilder();
        $query->setEvent('storage.tableCreated')
            ->setObjectId($linkedBucketId . '.' . self::EXTERNAL_TABLE_2)
            ->setTokenId($this->tokenId);
        $this->assertEventWithRetries($this->linkingClient, $assertCallback, $query);

        $linkingTables = $this->linkingClient->listTables($linkedBucketId);
        $this->assertCount(2, $linkingTables);
        $linkingTable = $linkingTables[0];

        $dataPreview = $this->linkingClient->getTableDataPreview($linkingTable['id']);
        $this->assertEquals(
            <<<EXPECTED
"ID","LASTNAME","GENDER"
"1","Novák","male"

EXPECTED,
            $dataPreview,
        );

        $result = $linkingSnowflakeDb->fetchAll(sprintf(
            'SELECT * FROM %s.%s.%s',
            SnowflakeQuote::quoteSingleIdentifier(self::EXTERNAL_DB),
            SnowflakeQuote::quoteSingleIdentifier(self::EXTERNAL_SCHEMA),
            SnowflakeQuote::quoteSingleIdentifier(self::EXTERNAL_TABLE),
        ));
        $this->assertEquals(
            [
                [
                    'ID' => 1,
                    'LASTNAME' => 'Novák',
                    'GENDER' => 'male',
                ],
            ],
            $result,
        );

        $result2 = $linkingSnowflakeDb->fetchAll(sprintf(
            'SELECT * FROM %s.%s.%s',
            SnowflakeQuote::quoteSingleIdentifier(self::EXTERNAL_DB),
            SnowflakeQuote::quoteSingleIdentifier(self::EXTERNAL_SCHEMA),
            SnowflakeQuote::quoteSingleIdentifier(self::EXTERNAL_TABLE_2),
        ));
        $this->assertEquals(
            [
                [
                    'ID' => 1,
                    'DESC' => 'important description',
                ],
            ],
            $result2,
        );

        $db->executeQuery(sprintf(
            'DROP TABLE %s',
            SnowflakeQuote::quoteSingleIdentifier(self::EXTERNAL_TABLE_2),
        ));

        $db->executeQuery(sprintf(
            'ALTER TABLE %s DROP COLUMN GENDER;',
            SnowflakeQuote::quoteSingleIdentifier(self::EXTERNAL_TABLE),
        ));

        try {
            $linkingSnowflakeDb->fetchAll(sprintf(
                'SELECT * FROM %s.%s.%s',
                SnowflakeQuote::quoteSingleIdentifier(self::EXTERNAL_DB),
                SnowflakeQuote::quoteSingleIdentifier(self::EXTERNAL_SCHEMA),
                SnowflakeQuote::quoteSingleIdentifier(self::EXTERNAL_TABLE_2),
            ));
            $this->fail('Should fail.');
        } catch (Throwable $e) {
            $this->assertEquals(
                "odbc_prepare(): SQL error: SQL compilation error:
Object 'EXT_DB.EXT_SCHEMA.EXT_TABLE_2' does not exist or not authorized., SQL state S0002 in SQLPrepare",
                $e->getMessage(),
            );
        }

        $this->_client->refreshBucket($registeredBucketId);

        $assertCallback = function ($events) {
            $this->assertCount(1, $events);
        };
        $query = new EventsQueryBuilder();
        $query->setEvent('storage.tableDeleted')
            ->setObjectId($linkedBucketId . '.' . self::EXTERNAL_TABLE_2)
            ->setTokenId($this->tokenId);
        $this->assertEventWithRetries($this->linkingClient, $assertCallback, $query);

        $assertCallback = function ($events) {
            $this->assertCount(2, $events);
        };
        $query = new EventsQueryBuilder();
        $query->setEvent('storage.tableColumnsUpdated')
            ->setObjectId($linkedBucketId . '.' . self::EXTERNAL_TABLE)
            ->setTokenId($this->tokenId);
        $this->assertEventWithRetries($this->linkingClient, $assertCallback, $query);

        $linkingTables = $this->linkingClient->listTables($linkedBucketId);
        $this->assertCount(1, $linkingTables);

        $dataPreview = $this->linkingClient->getTableDataPreview($linkingTable['id']);
        $this->assertEquals(
            <<<EXPECTED
"ID","LASTNAME"
"1","Novák"

EXPECTED,
            $dataPreview,
        );

        // test RO works
        /** @var \Keboola\Db\Import\Snowflake\Connection $linkingSnowflakeDb */
        $linkingSnowflakeDb = $linkingBackend->getDb();

        $result = $linkingSnowflakeDb->fetchAll(sprintf(
            'SELECT * FROM %s.%s.%s',
            SnowflakeQuote::quoteSingleIdentifier(self::EXTERNAL_DB),
            SnowflakeQuote::quoteSingleIdentifier(self::EXTERNAL_SCHEMA),
            SnowflakeQuote::quoteSingleIdentifier(self::EXTERNAL_TABLE),
        ));
        $this->assertEquals(
            [
                [
                    'ID' => 1,
                    'LASTNAME' => 'Novák',
                ],
            ],
            $result,
        );

        // REFRESH END

        $this->linkingClient->dropBucket($linkedBucketId, ['force' => true]);

        try {
            $this->linkingClient->getTableDataPreview($linkingTable['id']);
            $this->fail('Select should fail.');
        } catch (Throwable $e) {
            $this->assertSame(404, $e->getCode());
            $this->assertStringContainsString(
                sprintf(
                    'The table "EXT_TABLE" was not found in the bucket "%s" in the project',
                    $linkedBucketId,
                ),
                $e->getMessage(),
            );
        }

        try {
            $linkingSnowflakeDb->fetchAll(sprintf(
                'SELECT * FROM %s.%s.%s',
                SnowflakeQuote::quoteSingleIdentifier(self::EXTERNAL_DB),
                SnowflakeQuote::quoteSingleIdentifier(self::EXTERNAL_SCHEMA),
                SnowflakeQuote::quoteSingleIdentifier(self::EXTERNAL_TABLE),
            ));
            $this->fail('Select should fail.');
        } catch (Throwable $e) {
            $this->assertEquals(sprintf(
                'odbc_prepare(): SQL error: SQL compilation error:
Database \'%s\' does not exist or not authorized., SQL state 02000 in SQLPrepare',
                self::EXTERNAL_DB,
            ), $e->getMessage());
        }

        // LINKING END

        $this->shareClient->unshareBucket($registeredBucketId);
        $unsharedBucket = $this->_client->getBucket($registeredBucketId);
        $this->assertNull($unsharedBucket['sharing']);

        $db->executeQuery(
            sprintf(
                'DROP DATABASE %s;',
                SnowflakeQuote::quoteSingleIdentifier(self::EXTERNAL_DB),
            ),
        );
    }

    public function testWorkspaceSchemaAsSharedBucket(): void
    {
        $bucketName = $this->getTestBucketName($this->generateDescriptionForTestObject());

        $this->forceUnshareBucketIfExists($this->shareClient, self::STAGE_IN . '.' . $bucketName, true);
        $this->dropBucketIfExists($this->_client, self::STAGE_IN . '.' . $bucketName, true);

        $this->initEvents($this->_client);

        $workspaces = new Workspaces($this->_client);
        $workspace = $workspaces->createWorkspace([], true);
        $workspaceBackend = WorkspaceBackendFactory::createWorkspaceBackend($workspace);

        $workspaceBackend->createTable(self::EXTERNAL_TABLE, ['ID' => 'INT', 'LASTNAME' => 'VARCHAR(255)']);
        $workspaceBackend->executeQuery(sprintf(
            'INSERT INTO %s (ID, LASTNAME) VALUES (1, %s)',
            SnowflakeQuote::quoteSingleIdentifier(self::EXTERNAL_TABLE),
            SnowflakeQuote::quote('Novák'),
        ));

        $registeredBucketId = $this->_client->registerBucket(
            $bucketName,
            [$workspace['connection']['database'], $workspace['connection']['schema']],
            self::STAGE_IN,
            'I\'am in workspace',
            'snowflake',
            $bucketName . '-registered',
        );

        $tables = $this->_client->listTables(self::STAGE_IN . '.' . $bucketName);
        $this->assertCount(1, $tables);

        $shareToken = $this->linkingClient->verifyToken();
        $targetProjectId = $shareToken['owner']['id'];

        $this->shareClient->shareBucketToProjects($registeredBucketId, [$targetProjectId]);

        $sharedBucket = $this->_client->getBucket($registeredBucketId);
        $this->assertEquals('specific-projects', $sharedBucket['sharing']);
        $this->assertEquals($targetProjectId, $sharedBucket['sharingParameters']['projects'][0]['id']);

        // LINKING START
        $this->dropBucketIfExists($this->linkingClient, self::STAGE_IN . '.' . $bucketName, true);

        $token = $this->_client->verifyToken();
        $linkedBucketId = $this->linkingClient->linkBucket(
            $bucketName,
            self::STAGE_IN,
            $token['owner']['id'],
            $sharedBucket['id'],
            $bucketName . '-linked',
        );
        $linkedBucket = $this->linkingClient->getBucket($linkedBucketId);
        $this->assertEquals($sharedBucket['id'], $linkedBucket['sourceBucket']['id']);

        $linkingTables = $this->linkingClient->listTables($linkedBucketId);
        $this->assertCount(1, $tables);
        $linkingTable = $linkingTables[0];

        $dataPreview = $this->linkingClient->getTableDataPreview($linkingTable['id']);
        $this->assertEquals(
            <<<EXPECTED
"ID","LASTNAME"
"1","Novák"

EXPECTED,
            $dataPreview,
        );

        $linkingWorkspaces = new Workspaces($this->linkingClient);
        $linkingWorkspace = $linkingWorkspaces->createWorkspace([], true);
        $linkingBackend = WorkspaceBackendFactory::createWorkspaceBackend($linkingWorkspace);

        /** @var \Keboola\Db\Import\Snowflake\Connection $linkingSnowflakeDb */
        $linkingSnowflakeDb = $linkingBackend->getDb();

        $result = $linkingSnowflakeDb->fetchAll(sprintf(
            'SELECT * FROM %s.%s.%s',
            SnowflakeQuote::quoteSingleIdentifier($workspace['connection']['database']),
            SnowflakeQuote::quoteSingleIdentifier($workspace['name']),
            SnowflakeQuote::quoteSingleIdentifier(self::EXTERNAL_TABLE),
        ));
        $this->assertEquals(
            [
                [
                    'ID' => 1,
                    'LASTNAME' => 'Novák',
                ],
            ],
            $result,
        );

        // REFRESH START

        $workspaceBackend->executeQuery(sprintf(
            'ALTER TABLE %s ADD COLUMN GENDER VARCHAR(50);',
            SnowflakeQuote::quoteSingleIdentifier(self::EXTERNAL_TABLE),
        ));

        $workspaceBackend->executeQuery(sprintf(
            'UPDATE %s SET GENDER = %s WHERE ID = 1;',
            SnowflakeQuote::quoteSingleIdentifier(self::EXTERNAL_TABLE),
            SnowflakeQuote::quote('male'),
        ));

        $workspaceBackend->executeQuery(sprintf(
            'CREATE TABLE %s (ID INT, DESC VARCHAR(255))',
            SnowflakeQuote::quoteSingleIdentifier(self::EXTERNAL_TABLE_2),
        ));
        $workspaceBackend->executeQuery(sprintf(
            'INSERT INTO %s (ID, DESC) VALUES (1, %s)',
            SnowflakeQuote::quoteSingleIdentifier(self::EXTERNAL_TABLE_2),
            SnowflakeQuote::quote('important description'),
        ));

        $expectedEventsBeforeRefresh = 6;
        $this->assertEventsCallback(
            $this->_client,
            function ($events) use ($expectedEventsBeforeRefresh) {
                $this->assertCount($expectedEventsBeforeRefresh, $events);
            },
            20,
        );

        $this->_client->refreshBucket($registeredBucketId);

        $expectedRefreshEvents = 3;
        $this->assertEventsCallback(
            $this->_client,
            function ($events) use ($expectedEventsBeforeRefresh, $expectedRefreshEvents) {
                $this->assertCount($expectedEventsBeforeRefresh + $expectedRefreshEvents, $events);
                $expectedEvents = ['storage.tableColumnsUpdated', 'storage.tableCreated', 'storage.bucketRefreshed'];
                for ($i = 0; $i < $expectedRefreshEvents; $i++) {
                    $this->assertSame($expectedEvents[$i], $events[$i]['event']);
                }
            },
            20,
        );

        $linkingTables = $this->linkingClient->listTables($linkedBucketId);
        $this->assertCount(2, $linkingTables);
        $linkingTable = $linkingTables[0];

        $dataPreview = $this->linkingClient->getTableDataPreview($linkingTable['id']);
        $this->assertEquals(
            <<<EXPECTED
"ID","LASTNAME","GENDER"
"1","Novák","male"

EXPECTED,
            $dataPreview,
        );

        $result = $linkingSnowflakeDb->fetchAll(sprintf(
            'SELECT * FROM %s.%s.%s',
            SnowflakeQuote::quoteSingleIdentifier($workspace['connection']['database']),
            SnowflakeQuote::quoteSingleIdentifier($workspace['name']),
            SnowflakeQuote::quoteSingleIdentifier(self::EXTERNAL_TABLE),
        ));
        $this->assertEquals(
            [
                [
                    'ID' => 1,
                    'LASTNAME' => 'Novák',
                    'GENDER' => 'male',
                ],
            ],
            $result,
        );

        $result2 = $linkingSnowflakeDb->fetchAll(sprintf(
            'SELECT * FROM %s.%s.%s',
            SnowflakeQuote::quoteSingleIdentifier($workspace['connection']['database']),
            SnowflakeQuote::quoteSingleIdentifier($workspace['name']),
            SnowflakeQuote::quoteSingleIdentifier(self::EXTERNAL_TABLE_2),
        ));
        $this->assertEquals(
            [
                [
                    'ID' => 1,
                    'DESC' => 'important description',
                ],
            ],
            $result2,
        );

        /** @var \Keboola\Db\Import\Snowflake\Connection $linkingSnowflakeDb */
        $linkingSnowflakeDb = $linkingBackend->getDb();

        $result3 = $linkingSnowflakeDb->fetchAll(sprintf(
            'SELECT * FROM %s.%s.%s',
            SnowflakeQuote::quoteSingleIdentifier($workspace['connection']['database']),
            SnowflakeQuote::quoteSingleIdentifier($workspace['name']),
            SnowflakeQuote::quoteSingleIdentifier(self::EXTERNAL_TABLE_2),
        ));
        $this->assertEquals(
            [
                [
                    'ID' => 1,
                    'DESC' => 'important description',
                ],
            ],
            $result3,
        );

        $workspaceBackend->executeQuery(sprintf(
            'DROP TABLE %s',
            SnowflakeQuote::quoteSingleIdentifier(self::EXTERNAL_TABLE_2),
        ));

        try {
            $linkingSnowflakeDb->fetchAll(sprintf(
                'SELECT * FROM %s.%s.%s',
                SnowflakeQuote::quoteSingleIdentifier($workspace['connection']['database']),
                SnowflakeQuote::quoteSingleIdentifier($workspace['name']),
                SnowflakeQuote::quoteSingleIdentifier(self::EXTERNAL_TABLE_2),
            ));
            $this->fail('Should fail.');
        } catch (Throwable $e) {
            $this->assertEquals(sprintf(
                'odbc_prepare(): SQL error: SQL compilation error:
Object \'%s.%s.EXT_TABLE_2\' does not exist or not authorized., SQL state S0002 in SQLPrepare',
                $workspace['connection']['database'],
                $workspace['name'],
            ), $e->getMessage());
        }

        // REFRESH END

        $this->linkingClient->dropBucket($linkedBucketId, ['force' => true]);

        try {
            $linkingSnowflakeDb->fetchAll(sprintf(
                'SELECT * FROM %s.%s.%s',
                SnowflakeQuote::quoteSingleIdentifier($workspace['connection']['database']),
                SnowflakeQuote::quoteSingleIdentifier($workspace['name']),
                SnowflakeQuote::quoteSingleIdentifier(self::EXTERNAL_TABLE),
            ));
            $this->fail('Select should fail.');
        } catch (Throwable $e) {
            $this->assertEquals(sprintf(
                'odbc_prepare(): SQL error: SQL compilation error:
Database \'%s\' does not exist or not authorized., SQL state 02000 in SQLPrepare',
                $workspace['connection']['database'],
            ), $e->getMessage());
        }

        // LINKING END

        $this->shareClient->unshareBucket($registeredBucketId);
        $unsharedBucket = $this->_client->getBucket($registeredBucketId);
        $this->assertNull($unsharedBucket['sharing']);

        $workspaces->deleteWorkspace($workspace['id']);
    }

    private function prepareExternalFirstTable(\Doctrine\DBAL\Connection $db, array $guideExploded): void
    {
        $db->executeQuery(sprintf(
            'DROP DATABASE IF EXISTS %s;',
            SnowflakeQuote::quoteSingleIdentifier(self::EXTERNAL_DB),
        ));
        $db->executeQuery(sprintf(
            'CREATE DATABASE %s;',
            SnowflakeQuote::quoteSingleIdentifier(self::EXTERNAL_DB),
        ));
        $db->executeQuery(sprintf(
            'USE DATABASE %s;',
            SnowflakeQuote::quoteSingleIdentifier(self::EXTERNAL_DB),
        ));
        $db->executeQuery(sprintf(
            'CREATE SCHEMA %s;',
            SnowflakeQuote::quoteSingleIdentifier(self::EXTERNAL_SCHEMA),
        ));
        $db->executeQuery(sprintf(
            'USE SCHEMA %s;',
            SnowflakeQuote::quoteSingleIdentifier(self::EXTERNAL_SCHEMA),
        ));
        $db->executeQuery(sprintf(
            'CREATE TABLE %s (ID INT, LASTNAME VARCHAR(255));',
            SnowflakeQuote::quoteSingleIdentifier(self::EXTERNAL_TABLE),
        ));
        $db->executeQuery(sprintf(
            'USE WAREHOUSE %s',
            SnowflakeQuote::quoteSingleIdentifier('DEV'),
        ));
        $db->executeQuery(sprintf(
            'INSERT INTO %s (ID, LASTNAME) VALUES (1, %s)',
            SnowflakeQuote::quoteSingleIdentifier(self::EXTERNAL_TABLE),
            SnowflakeQuote::quote('Novák'),
        ));

        foreach ($guideExploded as $command) {
            if (str_starts_with($command, 'GRANT') && !str_contains($command, 'FUTURE')) {
                try {
                    $db->executeQuery($command);
                } catch (Exception $e) {
                    $this->fail($e->getMessage() . ': ' . $command);
                }
            }
        }
    }
}
