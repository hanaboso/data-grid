<?php declare(strict_types=1);

namespace DataGridTests;

use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Cache\FilesystemCache;
use Doctrine\Common\Persistence\Mapping\Driver\MappingDriverChain;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\Driver\AnnotationDriver;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\Setup;
use DoctrineExtensions\Query\Mysql\DateFormat;
use Exception;
use Hanaboso\PhpCheckUtils\PhpUnit\Traits\PrivateTrait;
use PHPUnit\Framework\TestCase;

/**
 * Class TestCaseAbstract
 *
 * @package DataGridTests
 */
abstract class TestCaseAbstract extends TestCase
{

    use PrivateTrait;

    protected const DATABASE = 'datagrid';

    private const   TEMP_DIR = '%s/../var/Doctrine2.ORM';

    /**
     * @var EntityManager
     */
    protected $em;

    /**
     * @throws Exception
     */
    protected function setUp(): void
    {
        $reader = new AnnotationReader();
        $driver = new MappingDriverChain();
        $driver->addDriver(new AnnotationDriver($reader, [sprintf('%s/Entity', __DIR__)]), 'DataGridTests\\Entity');

        $configuration = Setup::createAnnotationMetadataConfiguration(
            [sprintf('%s/Entity', __DIR__)],
            FALSE,
            sprintf(self::TEMP_DIR, __DIR__),
            new FilesystemCache(sprintf(self::TEMP_DIR, __DIR__))
        );
        $configuration->setMetadataDriverImpl($driver);
        $configuration->setProxyNamespace('Proxy');
        $configuration->setProxyDir(sprintf(self::TEMP_DIR, __DIR__));
        $configuration->setNamingStrategy(new UnderscoreNamingStrategy());
        $configuration->addCustomStringFunction('DATE_FORMAT', DateFormat::class);

        $this->em = EntityManager::create(
            [
                'driver'   => 'pdo_mysql',
                'host'     => getenv('MARIA_HOST') ?: '127.0.0.1',
                'user'     => getenv('MARIA_USER') ?: 'travis',
                'password' => getenv('MARIA_USER') ?: '',
                'dbname'   => static::DATABASE,
            ],
            $configuration
        );

        $schemaTool = new SchemaTool($this->em);
        $schemaTool->dropSchema($this->em->getMetadataFactory()->getAllMetadata());
        $schemaTool->createSchema($this->em->getMetadataFactory()->getAllMetadata());
    }

}
