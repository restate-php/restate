<?php

declare(strict_types=1);

// One invocation through the Restate core, for the FrankenPHP and PHP-FPM checks (flake.nix): Restate's start message
// and the handler's input (an invocation inv-1 whose input is "Mary"), the handler's output for $name, and the end of
// the attempt, with shared-core's logs captured. Returns what the checks compare: the invocation, its input and the
// output, or what went wrong.
//
// The frames are protocol v5 as Restate sends them, written by restate-php/restate's tests/Support/Protocol/FrameCodec
// (startFrame('inv-1', 1, ''), inputCommandFrame('"Mary"')).

const RESTATE_START = '00000000000000100a05696e762d311205696e762d311801';
const RESTATE_INPUT = '040000000000000a72080a06224d61727922';
// The end message, which closes every complete response body.
const RESTATE_END = '0003000000000000';

function restate_invoke(string $name): string
{
    $vm = new Restate\Extension\Vm([['content-type', 'application/vnd.restate.invocation.v5']], 'debug');

    try {
        $vm->notifyInput(hex2bin(RESTATE_START) . hex2bin(RESTATE_INPUT));
        $vm->notifyInputClosed();
        if (!$vm->isReadyToExecute()) {
            return 'the core is not ready to execute';
        }

        $input = $vm->input();
        $output = json_encode("Hello, {$name}!", JSON_THROW_ON_ERROR);
        $vm->writeOutputSuccess($output);
        $vm->end();

        $body = '';
        while (($chunk = $vm->takeOutput()) !== null) {
            $body .= $chunk;
        }
        if (!str_contains($body, $output) || !str_ends_with($body, hex2bin(RESTATE_END))) {
            return 'unexpected response body: ' . bin2hex($body);
        }
        if ($vm->takeLogs() === []) {
            return 'no shared-core logs captured';
        }

        return "{$input['invocation_id']} {$input['input']} => {$output}";
    } finally {
        $vm->free();
    }
}
