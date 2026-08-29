<?php

/**
 * Returns equation results for basic PAMDAs equation strings. Operators: Addition (+), Subtraction (-), Multiplication (*) and Division (/)
 * Example: 2 + 2 = 4
 */
function calculate(string $equation): int|float
{
    $matched         = [];
    $whiteSpaceRegex = '/\s/'
    $equation        = preg_replace(whiteSpaceRegex, '', $equation);
    
    preg_match('/^\d*[+-\/*]\d*[=]\d*/', $equation, $matched);
}