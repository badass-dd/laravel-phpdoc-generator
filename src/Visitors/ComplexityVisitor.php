<?php

namespace Badass\LazyDocs\Visitors;

use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;

class ComplexityVisitor extends NodeVisitorAbstract
{
    private int $cyclomaticComplexity = 1;

    private int $cognitiveComplexity = 0;

    private array $operators = [];

    private array $operands = [];

    private int $nestingLevel = 0;

    private int $maxNestingLevel = 0;

    public function enterNode(Node $node)
    {
        $this->updateCyclomaticComplexity($node);
        $this->updateCognitiveComplexity($node);
        $this->updateHalsteadMetrics($node);
        $this->updateNestingLevel($node, true);
    }

    public function leaveNode(Node $node)
    {
        $this->updateNestingLevel($node, false);
    }

    private function updateCyclomaticComplexity(Node $node): void
    {
        if ($node instanceof Stmt\If_ ||
            $node instanceof Stmt\ElseIf_ ||
            $node instanceof Stmt\For_ ||
            $node instanceof Stmt\Foreach_ ||
            $node instanceof Stmt\While_ ||
            $node instanceof Stmt\Do_ ||
            $node instanceof Stmt\Case_ ||
            $node instanceof Stmt\Catch_) {
            $this->cyclomaticComplexity++;
        }

        if ($node instanceof Node\Expr\BinaryOp\BooleanAnd ||
            $node instanceof Node\Expr\BinaryOp\BooleanOr ||
            $node instanceof Node\Expr\BinaryOp\LogicalAnd ||
            $node instanceof Node\Expr\BinaryOp\LogicalOr) {
            $this->cyclomaticComplexity++;
        }
    }

    private function updateCognitiveComplexity(Node $node): void
    {
        if ($this->isStructuralNode($node)) {
            $this->cognitiveComplexity += $this->nestingLevel + 1;
        }

        if ($node instanceof Stmt\Else_ || $node instanceof Stmt\ElseIf_) {
            $this->cognitiveComplexity++;
        }

        if ($node instanceof Node\Expr\BinaryOp && $this->nestingLevel > 0) {
            $this->cognitiveComplexity++;
        }
    }

    private function updateHalsteadMetrics(Node $node): void
    {
        if ($this->isOperator($node)) {
            $operator = get_class($node);
            $this->operators[$operator] = ($this->operators[$operator] ?? 0) + 1;
        }

        if ($this->isOperand($node)) {
            $operand = $this->getOperandIdentifier($node);
            $this->operands[$operand] = ($this->operands[$operand] ?? 0) + 1;
        }
    }

    private function updateNestingLevel(Node $node, bool $entering): void
    {
        if ($entering && $this->isStructuralNode($node)) {
            $this->nestingLevel++;
            $this->maxNestingLevel = max($this->maxNestingLevel, $this->nestingLevel);
        } elseif (! $entering && $this->isStructuralNode($node)) {
            $this->nestingLevel--;
        }
    }

    private function isStructuralNode(Node $node): bool
    {
        return $node instanceof Stmt\If_ ||
               $node instanceof Stmt\ElseIf_ ||
               $node instanceof Stmt\For_ ||
               $node instanceof Stmt\Foreach_ ||
               $node instanceof Stmt\While_ ||
               $node instanceof Stmt\Do_ ||
               $node instanceof Stmt\Switch_ ||
               $node instanceof Stmt\TryCatch_ ||
               $node instanceof Stmt\Function_ ||
               $node instanceof Stmt\ClassMethod;
    }

    private function isOperator(Node $node): bool
    {
        return $node instanceof Node\Expr\BinaryOp ||
               $node instanceof Node\Expr\UnaryMinus ||
               $node instanceof Node\Expr\UnaryPlus ||
               $node instanceof Node\Expr\BooleanNot ||
               $node instanceof Node\Expr\BitwiseNot ||
               $node instanceof Node\Expr\Assign ||
               $node instanceof Node\Expr\AssignOp;
    }

    private function isOperand(Node $node): bool
    {
        return $node instanceof Node\Expr\Variable ||
               $node instanceof Node\Scalar ||
               $node instanceof Node\Expr\ConstFetch ||
               $node instanceof Node\Expr\ClassConstFetch;
    }

    private function getOperandIdentifier(Node $node): string
    {
        if ($node instanceof Node\Expr\Variable && is_string($node->name)) {
            return '$'.$node->name;
        }

        if ($node instanceof Node\Scalar\String_) {
            return '"'.substr($node->value, 0, 20).'"';
        }

        if ($node instanceof Node\Scalar\LNumber) {
            return (string) $node->value;
        }

        if ($node instanceof Node\Scalar\DNumber) {
            return (string) $node->value;
        }

        if ($node instanceof Node\Expr\ConstFetch) {
            return $node->name->toString();
        }

        return get_class($node);
    }

    public function getCyclomaticComplexity(): int
    {
        return $this->cyclomaticComplexity;
    }

    public function getCognitiveComplexity(): int
    {
        return $this->cognitiveComplexity;
    }

    public function getHalsteadMetrics(): array
    {
        $n1 = count($this->operators);
        $n2 = count($this->operands);
        $N1 = array_sum($this->operators);
        $N2 = array_sum($this->operands);

        $vocabulary = $n1 + $n2;
        $length = $N1 + $N2;

        // Guard against zero/one vocabulary which would make log invalid or zero.
        if ($vocabulary <= 1 || $length === 0) {
            $volume = 0.0;
        } else {
            $volume = $length * log($vocabulary, 2);
        }

        // Avoid division by zero when there are no operands
        if ($n2 === 0) {
            $difficulty = 0.0;
        } else {
            $difficulty = ($n1 / 2) * ($N2 / $n2);
        }

        $effort = $difficulty * $volume;

        return [
            'distinct_operators' => $n1,
            'distinct_operands' => $n2,
            'total_operators' => $N1,
            'total_operands' => $N2,
            'vocabulary' => $vocabulary,
            'length' => $length,
            'volume' => $volume,
            'difficulty' => $difficulty,
            'effort' => $effort,
        ];
    }

    public function getMaintainabilityIndex(): float
    {
        $halstead = $this->getHalsteadMetrics();
        $cyclomatic = $this->cyclomaticComplexity;
        $cognitive = $this->cognitiveComplexity;

        $volume = max($halstead['volume'], 1);
        $complexity = max($cyclomatic + $cognitive, 1);

        $mi = 171 - 5.2 * log($volume) - 0.23 * $complexity - 16.2 * log($this->maxNestingLevel + 1);

        return max(min($mi, 100), 0);
    }

    public function getMaxNestingLevel(): int
    {
        return $this->maxNestingLevel;
    }
}
