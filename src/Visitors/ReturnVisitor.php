<?php

namespace Badass\LazyDocs\Visitors;

use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;

class ReturnVisitor extends NodeVisitorAbstract
{
    private array $returns = [];

    public function enterNode(Node $node)
    {
        if ($node instanceof Stmt\Return_) {
            $this->returns[] = $node;
        }
    }

    public function getReturns(): array
    {
        return $this->returns;
    }
}
