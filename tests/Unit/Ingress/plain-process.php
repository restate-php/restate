<?php

declare(strict_types=1);

/*
 * Uses every ingress client operation in a plain PHP process (IngressClientWithoutExtensionTest runs it with `php -n`:
 * no php.ini, so no Restate extension and only PHP's built-in modules), against a fake PSR-18 client, and prints what
 * it got together with the Restate classes it loaded.
 */

use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Restate\Ingress\IngressClient;
use Restate\Ingress\IngressException;
use Restate\Target;
use Restate\TerminalError;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$http = new class () implements ClientInterface {
    /** @var list<string> */
    public array $requests = [];

    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $this->requests[] = $request->getMethod() . ' ' . $path . ($request->getUri()->getQuery() === '' ? '' : '?' . $request->getUri()->getQuery());
        $factory = new HttpFactory();
        [$status, $body, $source] = match (true) {
            str_ends_with($path, '/send') => [202, '{"invocationId":"inv_1abc","status":"Accepted"}', null],
            str_starts_with($path, '/restate/awakeables/') => [202, '', null],
            str_ends_with($path, '/output') => [470, '{"code":470,"message":"the invocation exists but has not completed yet","source":"ingress"}', 'ingress'],
            $path === '/Payments/charge' => [400, '{"code":400,"message":"Payment declined","source":"invocation"}', 'invocation'],
            $path === '/Nope/handler' => [404, '{"code":404,"message":"service \'Nope\' not found","source":"ingress"}', 'ingress'],
            default => [200, '"ok"', null],
        };
        $response = $factory->createResponse($status)->withBody($factory->createStream($body))->withHeader('content-type', 'application/json');

        return $source === null ? $response : $response->withHeader('x-restate-error-source', $source);
    }
};

$client = IngressClient::connect('http://restate.invalid:8080', $http, new HttpFactory(), headers: ['authorization' => 'Bearer secret']);
$results = [];

$results['call'] = $client->serviceClient('Greeter')->request('greet', 'Mary')->call();
$send = $client->objectClient('Counter', 'alice')->request('add', 1)->idempotencyKey('add-1')->sendAfter(10);
$results['send'] = [$send->invocationId(), $send->status()->value];
$results['attach'] = $send->attach();
$results['output'] = $client->invocation('inv_1abc')->output()->isReady();
$results['idempotent'] = $client->idempotentInvocation(Target::service('Greeter', 'greet'), 'greet-1')->attach();
$workflow = $client->workflowClient('Checkout', 'order-1');
$results['submit'] = $workflow->submit(['item' => 'book'])->invocationId();
$results['workflowAttach'] = $workflow->attach();
$results['workflowOutput'] = $workflow->output()->isReady();
$client->resolveAwakeable('sign_1abc', ['approved' => true]);
$client->rejectAwakeable('sign_1abc', 'denied');

try {
    $client->serviceClient('Payments')->request('charge', ['amount' => 1])->call();
} catch (TerminalError $e) {
    $results['terminal'] = [$e->statusCode(), $e->getMessage()];
}

try {
    $client->serviceClient('Nope')->request('handler')->call();
} catch (IngressException $e) {
    $results['ingress'] = [$e->statusCode(), $e->errorSource()];
}

$restateTypes = array_values(array_filter(
    [...get_declared_classes(), ...get_declared_interfaces(), ...get_declared_traits()],
    static fn (string $type): bool => str_starts_with($type, 'Restate\\'),
));
sort($restateTypes);

echo json_encode([
    'results' => $results,
    'requests' => $http->requests,
    'restateTypes' => $restateTypes,
    'extensions' => get_loaded_extensions(),
], JSON_THROW_ON_ERROR);
