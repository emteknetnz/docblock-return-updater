<?php

use SilverStripe\Dev\BuildTask;

class UpdateDocblockReturnTypeTask extends BuildTask
{
    public function run($request)
    {
        $vendorDirs = [
            BASE_PATH . '/vendor/dnadesign',
            BASE_PATH . '/vendor/silverstripe',
            BASE_PATH . '/vendor/symbiote',
            BASE_PATH . '/vendor/bringyourownideas',
        ];
        foreach ($vendorDirs as $vendorDir) {
            if (!file_exists($vendorDir)) {
                continue;
            }
            foreach (scandir($vendorDir) as $subdir) {
                if (in_array($subdir, ['.', '..'])) {
                    continue;
                }
                $dir = "$vendorDir/$subdir";
                foreach (['src', 'code', 'tests'] as $d) {
                    $subdir = "$dir/$d";
                    if (file_exists($subdir)) {
                        $this->update($subdir);
                    }
                }
            }
        }
    }

    private function update(string $dir)
    {
        $paths = explode("\n", shell_exec("find $dir | grep .php"));
        $paths = array_filter($paths);
        foreach ($paths as $path) {
            if (is_dir($path)) {
                continue;
            }
            $contents = file_get_contents($path);
            if (!preg_match('#(\nabstract |\n)class (.+?)[ \n]#', $contents, $m)) {
                continue;
            }
            $class = $m[2];
            if (!preg_match('#\nnamespace (.+?);#', $contents, $m)) {
                continue;
            }
            $namespace = $m[1];
            $fqcn = $namespace . '\\' . $class;
            try {
                $reflClass = new ReflectionClass($fqcn);
            } catch (Error|Exception $e) {
                continue;
            }
            $reflMethods = $reflClass->getMethods();
            foreach ($reflMethods as $reflMethod) {
                if ($reflClass->getFileName() != $reflMethod->getFileName()) {
                    continue;
                }
                $returnType = $reflMethod->getReturnType();
                if ($returnType) {
                    continue;
                }
                $docblock = $reflMethod->getDocComment();
                if (!preg_match('#@return +([a-zA-Z0-9_\[\]\|]+)#', $docblock, $m)) {
                    continue;
                }
                $oldReturnTypeDocblock = $m[1];
                $old = explode('|', strtolower($oldReturnTypeDocblock));
                $new = explode('|', $oldReturnTypeDocblock);
                $returnTypes = ['null', 'false', 'true', 'int', 'float', 'string', 'array'];
                $contents = file_get_contents($reflMethod->getFileName());
                $arr = explode("\n", $contents);
                $startLine = $reflMethod->getStartLine();
                $slicedArr = array_slice($arr, $startLine, $reflMethod->getEndLine() - $startLine);
                $methodContents = implode("\n", $slicedArr);
                foreach ($returnTypes as $returnType) {
                    $returnTypeIsBool = in_array($returnType, ['true', 'false']);
                    if (in_array($returnType, $old)) {
                        continue;
                    }
                    // mixed includes null
                    // https://php.watch/versions/8.0/mixed-type
                    if (in_array('mixed', $old)) {
                        continue;
                    }
                    if ($returnTypeIsBool && (in_array('bool', $old) || in_array('boolean', $old))) {
                        continue;
                    }
                    if (strpos($methodContents, 'function') !== false) {
                        // return types within anonymous functions just make things too complex
                        continue;
                    }
                    if (in_array($returnType, ['null', 'false', 'true'])) {
                        if (strpos($methodContents, 'return ' . $returnType . ';') !== false) {
                            $new[] = $returnType;
                        }
                    }
                    if ($returnType == 'int') {
                        if (preg_match('#return [0-9]+;#', $methodContents)) {
                            $new[] = $returnType;
                        }
                    }
                    if ($returnType == 'float') {
                        if (preg_match('#return [0-9]+\.[0-9]+;#', $methodContents)) {
                            $new[] = $returnType;
                        }
                    }
                    if ($returnType == 'string') {
                        if (preg_match('#return [\'"].*?[\'"];#', $methodContents)) {
                            $new[] = $returnType;
                        }
                    }
                    if ($returnType == 'array') {
                        if (preg_match('#return \[.*?\];#', $methodContents)) {
                            if (!in_array('arrayaccess', $old)) {
                                $existingArrayType = false;
                                foreach ($old as $t) {
                                    // e.g. int[]
                                    if (strpos($t, '[') !== false) {
                                        $existingArrayType = true;
                                    }
                                }
                                if (!$existingArrayType) {
                                    $new[] = $returnType;
                                }
                            }
                        }
                    }
                    if (in_array('true', $new) && in_array('false', $new)) {
                        $new = array_filter($new, fn($v) => !in_array($v, ['true', 'false']));
                        $new[] = 'bool';
                    }
                }
                $newReturnTypeDocblock = implode('|', $new);
                if ($newReturnTypeDocblock == $oldReturnTypeDocblock) {
                    continue;
                }
                $method = $reflMethod->getName();
                $oldDocblock = $reflMethod->getDocComment();
                $newDocblock = str_replace(
                    "@return $oldReturnTypeDocblock",
                    "@return $newReturnTypeDocblock",
                    $oldDocblock
                );
                $contents = preg_replace(
                    sprintf("#(?s)%s\n([^\n]+)function %s#", trim(preg_quote($oldDocblock)), $method),
                    trim($newDocblock) . "\n" . '$1function ' . $method,
                    $contents
                );
                file_put_contents($path, $contents);
                echo "Updated return type for $dir $class::$method()\n";
            }
        }
    }
}