<?php
namespace Pulsestorm\Magento2\Cli\Generate\Di;
use function Pulsestorm\Pestle\Importer\pestle_import;
pestle_import('Pulsestorm\Magento2\Cli\Library\input');
pestle_import('Pulsestorm\Magento2\Cli\Library\output');
pestle_import('Pulsestorm\Magento2\Cli\Library\getDiLinesFromMage2ClassName');
pestle_import('Pulsestorm\Cli\Token_Parse\token_get_all');
pestle_import('Pulsestorm\Magento2\Cli\Library\inputOrIndex');
pestle_import('Pulsestorm\Magento2\Cli\Library\writeStringToFile');

use stdClass;
function getClassIndent()
{
    return '    ';
}

function arrayContainsConstructToken($tokens)
{
    foreach($tokens as $token)
    {
        if($token->token_name === 'T_STRING' && $token->token_value === '__construct')
        {
            return true;
        }
    }
    return false;
}

function insertConstrctorIntoPhpClassFileTokens($tokens)
{
    $indent = getClassIndent();
    $new_tokens = [];
    $state  = 0;
    $c      = 0;
    foreach($tokens as $token)
    {
        $new_tokens[] = $token;    
        if($state === 0 && $token->token_name === 'T_CLASS')
        {            
            $state = FOUND_CLASS_KEYWORD;
        }
        
        if($state === FOUND_CLASS_KEYWORD && $token->token_value === '{')
        {
            $state = FOUND_OPENING_CLASS_BRACKET;
            $tmp = new stdClass;
            //$tmp->token_value = "\n" . $indent . '#Property Here' . "\n";
            $tmp->token_value = "\n" . $indent  .   
                'public function __construct()' . "\n" .
                $indent . '{' . "\n" . $indent . '}' . "\n";
            
            $new_tokens[] = $tmp;           
        }        
        
        $c++;
    }
    
    return token_get_all(implodeTokensIntoContents($new_tokens));
}

function implodeTokensIntoContents($tokens)
{
    $values = array_map(function($token){
        return $token->token_value;
    }, $tokens);
        
    return implode('', $values);
}

function addCommaIfSpoolBackwardsRevealsConstructorParam($tokens)
{
    $starting_index = count($tokens) - 1;
    for($i=$starting_index;$i>-1;$i--)
    {
        $token = $tokens[$i];
        //got back to opening (, time to return
        if($token->token_value === '(')
        {
            return $tokens;
        }
        
        //found whitespace. Remove, continue backwards
        if($token->token_name === 'T_WHITESPACE')
        {
            continue;
        }
        
        //if we get here, that means there IS another param, slide on 
        //the comma and then return the tokens
        $tmp = new stdClass;
        $tmp->token_value = ',';
        $before = array_slice($tokens, 0,$i+1);
        $after  = array_slice($tokens, $i+1);
        // $new_tokens = $tokens;
        $new_tokens = array_merge($before, [$tmp], $after);
        return $new_tokens; 
    }
    
    return $tokens;
}

function trimWhitespaceFromEndOfTokenArray($tokens)
{
    $starting_index = count($tokens) - 1;
    for($i=$starting_index;$i>-1;$i--)
    {
        if($tokens[$i]->token_name !== 'T_WHITESPACE')
        {
            return $tokens;
        }
        unset($tokens[$i]);
    }
}

function original($argv)
{
    if(count($argv) === 0)
    {
        $argv = [input("Which class?", 'Pulsestorm\Helloworld\Helper\Config')];
    }
    $class = array_shift($argv);
    
    output("DI Lines");   
    output('-----------------------');             
    output(implode("\n",getDiLinesFromMage2ClassName($class)));
    output('');
}

function defineStates()
{
    define('FOUND_CLASS_KEYWORD'          , 1);
    define('FOUND_OPENING_CLASS_BRACKET'  , 2);
    define('FOUND_CONSTRUCT'              , 3);
    define('FOUND_CONSTRUCT_CLOSE_PAREN'  , 4);
    define('FOUND_CONSTRUCT_OPEN_BRACKET' , 5);
    // define('FOUND_', X);
}
/**
* Injects a dependency into a class constructor
* Inserts a property, __construct paramater, and parameter assignment
*
* Description
* @command generate_di
*/
function pestle_cli($argv)
{
    ##original($argv);
    defineStates();

    $file  = inputOrIndex(
        "Which file are we injecting into?", null, $argv, 0
    );   
    
    $file = realpath($file);
    if(!$file)
    {
        exit("Could not find $file.\n");
    } 
    $class = inputOrIndex(
        "Which class to inject?", 'Magento\Catalog\Model\ProductFactory',
        $argv, 1);    

    $di_lines = (object) getDiLinesFromMage2ClassName($class);
    $di_lines->parameter = trim(trim($di_lines->parameter,','));        
    
    $indent   = getClassIndent();
    $contents = file_get_contents($file);
    $tokens   = token_get_all($contents);    
    
    $has_constructor = arrayContainsConstructToken($tokens);
    if(!$has_constructor)
    {
        $tokens = insertConstrctorIntoPhpClassFileTokens($tokens);
    }
        
    $state  = 0;
    $c      = 0;
    $new_tokens = [];
    foreach($tokens as $token)
    {
        $new_tokens[] = $token;
        if($state === 0 && $token->token_name === 'T_CLASS')
        {            
            $state = FOUND_CLASS_KEYWORD;
        }
        
        if($state === FOUND_CLASS_KEYWORD && $token->token_value === '{')
        {
            $state = FOUND_OPENING_CLASS_BRACKET;
            $tmp = new stdClass;
            //$tmp->token_value = "\n" . $indent . '#Property Here' . "\n";
            $tmp->token_value = "\n" . $indent . $di_lines->property . "\n";
            
            $new_tokens[] = $tmp;           
        }
        
        if($state === FOUND_OPENING_CLASS_BRACKET && $token->token_value === '__construct')
        {
            $state = FOUND_CONSTRUCT;
        }
        
        if($state === FOUND_CONSTRUCT && $token->token_value === ')')
        {
            $state = FOUND_CONSTRUCT_CLOSE_PAREN;
            $tmp = new stdClass;
            $tmp->token_value = "\n" . $indent . $indent . $di_lines->parameter;
            

            $current_token = array_pop($new_tokens);
            $new_tokens   = trimWhitespaceFromEndOfTokenArray($new_tokens);
            $new_tokens   = addCommaIfSpoolBackwardsRevealsConstructorParam(
                $new_tokens);
                
                            
            $new_tokens[] = $tmp;             
            $new_tokens[] = $current_token;             
        }
        
        if($state === FOUND_CONSTRUCT_CLOSE_PAREN && $token->token_value === '{')
        {
            $state = FOUND_CONSTRUCT_OPEN_BRACKET;
            $tmp = new stdClass;
            // $tmp->token_value = "\n" . $indent . '#Property Assignment Here' . "\n";
            $tmp->token_value = "\n" . $indent . $indent . 
                $di_lines->assignment;
            
            $new_tokens[] = $tmp;              
        }

        $c++;
    }
    
    $contents = implodeTokensIntoContents($new_tokens);
    
    writeStringToFile($file, $contents);    
}