<?php

declare(strict_types=1);

namespace MakinaCorpus\QueryBuilder\Bridge\Pdo\Query;

use MakinaCorpus\QueryBuilder\Query\Merge;

class PdoMerge extends Merge
{
    use PdoQueryTrait;
}
