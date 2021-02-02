<?php

/*
 * This file is part of the FOSElasticaBundle package.
 *
 * (c) FriendsOfSymfony <https://friendsofsymfony.github.com/>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FOS\ElasticaBundle\Tests\Unit\Index;

use Elastica\Client;
use FOS\ElasticaBundle\Configuration\ConfigManager;
use FOS\ElasticaBundle\Configuration\IndexConfig;
use FOS\ElasticaBundle\Elastica\Index;
use FOS\ElasticaBundle\Event\PostIndexResetEvent;
use FOS\ElasticaBundle\Event\PreIndexResetEvent;
use FOS\ElasticaBundle\Index\AliasProcessor;
use FOS\ElasticaBundle\Index\IndexManager;
use FOS\ElasticaBundle\Index\MappingBuilder;
use FOS\ElasticaBundle\Index\Resetter;
use FOS\ElasticaBundle\Index\ResetterInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface as LegacyEventDispatcherInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class ResetterTest extends TestCase
{
    /**
     * @var Resetter
     */
    private $resetter;

    private $aliasProcessor;
    private $configManager;
    private $dispatcher;
    private $elasticaClient;
    private $indexManager;
    private $mappingBuilder;

    protected function setUp(): void
    {
        $this->aliasProcessor = $this->createMock(AliasProcessor::class);
        $this->configManager = $this->createMock(ConfigManager::class);
        if (interface_exists(EventDispatcherInterface::class)) {
            // Symfony >= 4.3
            $this->dispatcher = $this->createMock(EventDispatcherInterface::class);
        } else {
            // Symfony 3.4
            $this->dispatcher = $this->createMock(LegacyEventDispatcherInterface::class);
        }
        $this->elasticaClient = $this->createMock(Client::class);
        $this->indexManager = $this->createMock(IndexManager::class);
        $this->mappingBuilder = $this->createMock(MappingBuilder::class);

        $this->resetter = new Resetter(
            $this->configManager,
            $this->indexManager,
            $this->aliasProcessor,
            $this->mappingBuilder,
            $this->dispatcher
        );
    }

    public function testResetAllIndexes()
    {
        $indexName = 'index1';
        $indexConfig = new IndexConfig([
            'name' => $indexName,
            'config' => [],
            'mapping' => [],
            'model' => [],
        ]);
        $this->mockIndex($indexName, $indexConfig);

        $this->configManager->expects($this->once())
            ->method('getIndexNames')
            ->will($this->returnValue([$indexName]));

        $this->dispatcherExpects([
            [$this->isInstanceOf(IndexResetEvent::class), IndexResetEvent::PRE_INDEX_RESET],
            [$this->isInstanceOf(IndexResetEvent::class), IndexResetEvent::POST_INDEX_RESET],
        ]);

        $this->elasticaClient->expects($this->exactly(2))
            ->method('requestEndpoint');

        $this->resetter->resetAllIndexes();
    }

    public function testResetIndex()
    {
        $indexConfig = new IndexConfig([
            'name' => 'index1',
            'config' => [],
            'mapping' => [],
            'model' => [],
        ]);
        $this->mockIndex('index1', $indexConfig);

        $this->dispatcherExpects([
            [$this->isInstanceOf(IndexResetEvent::class), IndexResetEvent::PRE_INDEX_RESET],
            [$this->isInstanceOf(IndexResetEvent::class), IndexResetEvent::POST_INDEX_RESET],
        ]);

        $this->elasticaClient->expects($this->exactly(2))
            ->method('requestEndpoint');

        $this->resetter->resetIndex('index1');
    }

    public function testResetIndexWithDifferentName()
    {
        $indexConfig = new IndexConfig([
            'name' => 'index1',
            'config' => [],
            'mapping' => [],
            'model' => [],
        ]);
        $this->mockIndex('index1', $indexConfig);
        $this->dispatcherExpects([
            [$this->isInstanceOf(IndexResetEvent::class), IndexResetEvent::PRE_INDEX_RESET],
            [$this->isInstanceOf(IndexResetEvent::class), IndexResetEvent::POST_INDEX_RESET],
        ]);

        $this->elasticaClient->expects($this->exactly(2))
            ->method('requestEndpoint');

        $this->resetter->resetIndex('index1');
    }

    public function testResetIndexWithDifferentNameAndAlias()
    {
        $indexConfig = new IndexConfig([
            'name' => 'index1',
            'elasticSearchName' => 'notIndex1',
            'use_alias' => true,
            'config' => [],
            'mapping' => [],
            'model' => [],
        ]);
        $index = $this->mockIndex('index1', $indexConfig);
        $this->dispatcherExpects([
            [$this->isInstanceOf(IndexResetEvent::class), IndexResetEvent::PRE_INDEX_RESET],
            [$this->isInstanceOf(IndexResetEvent::class), IndexResetEvent::POST_INDEX_RESET],
        ]);

        $this->aliasProcessor->expects($this->once())
            ->method('switchIndexAlias')
            ->with($indexConfig, $index, false);

        $this->elasticaClient->expects($this->exactly(2))
            ->method('requestEndpoint');

        $this->resetter->resetIndex('index1');
    }

    public function testFailureWhenMissingIndexDoesntDispatch()
    {
        $this->configManager->expects($this->once())
            ->method('getIndexConfiguration')
            ->with('nonExistant')
            ->will($this->throwException(new \InvalidArgumentException()));

        $this->indexManager->expects($this->never())
            ->method('getIndex');

        $this->expectException(\InvalidArgumentException::class);
        $this->resetter->resetIndex('nonExistant');
    }

    public function testResetType()
    {
        $typeConfig = new TypeConfig('type', [], []);
        $indexConfig = new IndexConfig('index', [], []);
        $this->mockType('type', 'index', $typeConfig, $indexConfig);

        $this->dispatcherExpects([
            [$this->isInstanceOf(IndexResetEvent::class), IndexResetEvent::PRE_INDEX_RESET],
            [$this->isInstanceOf(IndexResetEvent::class), IndexResetEvent::POST_INDEX_RESET],
            [$this->isInstanceOf(TypeResetEvent::class), TypeResetEvent::PRE_TYPE_RESET],
            [$this->isInstanceOf(TypeResetEvent::class), TypeResetEvent::POST_TYPE_RESET],
        ]);

        $this->elasticaClient->expects($this->exactly(3))
            ->method('requestEndpoint');

        $this->resetter->resetIndexType('index', 'type');
    }

    public function testResetTypeWithChangedSettings()
    {
        $settingsValue = [
            'analysis' => [
                'analyzer' => [
                    'test_analyzer' => [
                        'type' => 'standard',
                        'tokenizer' => 'standard',
                    ],
                ],
            ],
        ];
        $typeConfig = new TypeConfig('type', [], []);
        $indexConfig = new IndexConfig('index', [], ['settings' => $settingsValue]);
        $this->mockType('type', 'index', $typeConfig, $indexConfig);

        $this->elasticaClient->expects($this->exactly(3))
            ->method('requestEndpoint');

        $this->resetter->resetIndexType('index', 'type');
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testNonExistantResetType()
    {
        $this->configManager->expects($this->once())
            ->method('getTypeConfiguration')
            ->with('index', 'nonExistant')
            ->will($this->throwException(new \InvalidArgumentException()));

        $this->indexManager->expects($this->never())
            ->method('getIndex');

        $this->resetter->resetIndexType('index', 'nonExistant');
    }

    public function testPostPopulateWithoutAlias()
    {
        $this->mockIndex('index', new IndexConfig([
            'name' => 'index',
            'config' => [],
            'mapping' => [],
            'model' => [],
        ]));

        $this->indexManager->expects($this->never())
            ->method('getIndex');
        $this->aliasProcessor->expects($this->never())
            ->method('switchIndexAlias');

        $this->resetter->switchIndexAlias('index');
    }

    public function testPostPopulate()
    {
        $indexConfig = new IndexConfig([
            'name' => 'index1',
            'use_alias' => true,
            'config' => [],
            'mapping' => [],
            'model' => [],
        ]);
        $index = $this->mockIndex('index', $indexConfig);

        $this->aliasProcessor->expects($this->once())
            ->method('switchIndexAlias')
            ->with($indexConfig, $index);

        $this->resetter->switchIndexAlias('index');
    }

    public function testResetterImplementsResetterInterface()
    {
        $this->assertInstanceOf(ResetterInterface::class, $this->resetter);
    }

    private function dispatcherExpects(array $events)
    {
        $expectation = $this->dispatcher->expects($this->exactly(\count($events)))
            ->method('dispatch');

        if ($this->dispatcher instanceof LegacyEventDispatcherInterface) {
            // Symfony 3.4
            $events = array_map(static function (array $event): array {
                return array_reverse($event);
            }, $events);
        }

        call_user_func_array([$expectation, 'withConsecutive'], $events);
    }

    private function mockIndex($indexName, IndexConfig $config, $mapping = [])
    {
        $this->configManager->expects($this->atLeast(1))
            ->method('getIndexConfiguration')
            ->with($indexName)
            ->will($this->returnValue($config));
        $index = new Index($this->elasticaClient, $indexName);
        $this->indexManager->expects($this->any())
            ->method('getIndex')
            ->with($indexName)
            ->willReturn($index);
        $this->mappingBuilder->expects($this->any())
            ->method('buildIndexMapping')
            ->with($config)
            ->willReturn($mapping);

        return $index;
    }
}
