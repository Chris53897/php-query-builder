<?php

declare(strict_types=1);

namespace MakinaCorpus\QueryBuilder\Tests\Functional;

use MakinaCorpus\QueryBuilder\Expression\Concat;
use MakinaCorpus\QueryBuilder\Expression\Lpad;
use MakinaCorpus\QueryBuilder\Expression\Rpad;
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
