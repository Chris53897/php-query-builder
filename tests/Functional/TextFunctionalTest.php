<?php

declare(strict_types=1);

namespace MakinaCorpus\QueryBuilder\Tests\Functional;

use MakinaCorpus\QueryBuilder\Bridge\AbstractBridge;
use MakinaCorpus\QueryBuilder\Expression\Concat;
use MakinaCorpus\QueryBuilder\Expression\Lpad;
use MakinaCorpus\QueryBuilder\Expression\Rpad;
use MakinaCorpus\QueryBuilder\Expression\StringHash;
use MakinaCorpus\QueryBuilder\Query\Select;
use MakinaCorpus\QueryBuilder\Tests\Bridge\Doctrine\DoctrineTestCase;

class TextFunctionalTest extends DoctrineTestCase
{
    public function testConcat(): void
    {
        $select = new Select();
        $select->columnRaw(new Concat('foo', '-', 'bar'));

        self::assertSame(
            'foo-bar',
            $this->executeDoctrineQuery($select)->fetchOne(),
        );
    }

    public function testMd5(): void
    {
        $this->skipIfDatabase(AbstractBridge::SERVER_SQLITE, 'SQLite does not have any hash function.');
        $this->skipIfDatabase(AbstractBridge::SERVER_SQLSERVER, 'SQL Server actually returns a hash, but not the right one ?!');

        $select = new Select();
        $select->columnRaw(new StringHash('foo', 'md5'));

        self::assertSame(
            'acbd18db4cc2f85cedef654fccc4a4d8',
            $this->executeDoctrineQuery($select)->fetchOne(),
        );
    }

    public function testSha1(): void
    {
        $this->skipIfDatabase(AbstractBridge::SERVER_POSTGRESQL, 'pgcrypto extension must be enabled.');
        $this->skipIfDatabase(AbstractBridge::SERVER_SQLITE, 'SQLite does not have any hash function.');
        $this->skipIfDatabase(AbstractBridge::SERVER_SQLSERVER, 'SQL Server actually returns a hash, but not the right one ?!');

        $select = new Select();
        $select->columnRaw(new StringHash('foo', 'sha1'));

        self::assertSame(
            '0beec7b5ea3f0fdbc95d0dd47f3c5bc275da8a33',
            $this->executeDoctrineQuery($select)->fetchOne(),
        );
    }

    public function testLpad(): void
    {
        $select = new Select();
        $select->columnRaw(new Lpad('foo', 7, 'ab'));

        self::assertSame(
            'ababfoo',
            $this->executeDoctrineQuery($select)->fetchOne(),
        );
    }

    public function testRpad(): void
    {
        $select = new Select();
        $select->columnRaw(new Rpad('foo', 7, 'ab'));

        self::assertSame(
            'fooabab',
            $this->executeDoctrineQuery($select)->fetchOne(),
        );
    }
}
