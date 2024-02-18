<?php

namespace Paysera\Bundle\RestBundle\Exception;

use Throwable;
use ReflectionProperty;
use ErrorException;

class FatalErrorException extends ErrorException
{
    public function __construct(
        string $message,
        int $code,
        int $severity,
        string $filename,
        int $lineno,
        int $traceOffset = null,
        bool $traceArgs = true,
        array $trace = null,
        Throwable $previous = null
    ) {
        parent::__construct($message, $code, $severity, $filename, $lineno, $previous);

        if (null !== $trace) {
            if (!$traceArgs) {
                foreach ($trace as &$frame) {
                    unset($frame['args'], $frame['this'], $frame);
                }
            }

            $this->setTrace($trace);
        } elseif (null !== $traceOffset) {
            if (function_exists('xdebug_get_function_stack') && $trace = @xdebug_get_function_stack()) {
                if (0 < $traceOffset) {
                    array_splice($trace, -$traceOffset);
                }

                foreach ($trace as &$frame) {
                    if (!isset($frame['type'])) {
                        // XDebug pre 2.1.1 doesn't currently set the call type key http://bugs.xdebug.org/view.php?id=695
                        if (isset($frame['class'])) {
                            $frame['type'] = '::';
                        }
                    } elseif ('dynamic' === $frame['type']) {
                        $frame['type'] = '->';
                    } elseif ('static' === $frame['type']) {
                        $frame['type'] = '::';
                    }

                    // XDebug also has a different name for the parameters array
                    if (!$traceArgs) {
                        unset($frame['params'], $frame['args']);
                    } elseif (isset($frame['params']) && !isset($frame['args'])) {
                        $frame['args'] = $frame['params'];
                        unset($frame['params']);
                    }
                }

                unset($frame);
                $trace = array_reverse($trace);
            } else {
                $trace = [];
            }

            $this->setTrace($trace);
        }
    }

    protected function setTrace($trace)
    {
        $traceReflector = new ReflectionProperty(\Exception::class, 'trace');
        $traceReflector->setAccessible(true);
        $traceReflector->setValue($this, $trace);
    }
}
