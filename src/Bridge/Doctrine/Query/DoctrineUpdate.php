<?php

declare(strict_types=1);

namespace MakinaCorpus\QueryBuilder\Bridge\Doctrine\Query;

use MakinaCorpus\QueryBuilder\Query\Update;

class DoctrineUpdate extends Update
{
    use DoctrineQueryTrait;
}
