<?php

namespace App\Controller;

use Psr\Cache\InvalidArgumentException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * @Route("/dropbox", name="dropbox_oauth_")
 */
class DropboxOauthController
{
    private HttpClientInterface $client;
    private UrlGeneratorInterface $generator;

    private const SESSION_STATE = 'dropbox.session.state';
    private const SESSION_PARAMETERS = 'dropbox.session.parameters';

    public function __construct(
        HttpClientInterface $client,
        UrlGeneratorInterface $generator
    ) {
        $this->client = $client;
        $this->generator = $generator;
    }

    /**
     * @Route("/authorize", methods={"GET"}, name="authorize")
     */
    public function authorizeAction(Request $request): RedirectResponse
    {
        $parameters = $this->getParameters($request, ['_wpnonce', 'redirect_uri']);

        try {
            // Create a random (unused) state key.
            $state = md5(random_bytes(16));

            $session = $request->getSession();
            $session->set(self::SESSION_STATE, $state);
            $session->set(self::SESSION_PARAMETERS, $parameters);
        } catch (\Exception $e) {
            throw new BadRequestHttpException('Could not process the request.', $e);
        }

        $params = [
            'client_id' => $_ENV['DROPBOX_APP_KEY'],
            'response_type' => 'code',
            'token_access_type' => 'offline',
            'redirect_uri' => $this->generator->generate(
                'dropbox_oauth_postback', [], UrlGeneratorInterface::ABSOLUTE_URL,
            ),
            'state' => $state,
        ];

        $auth_url = 'https://www.dropbox.com/oauth2/authorize?' . http_build_query($params);

        return new RedirectResponse($auth_url);
    }

    /**
     * @Route("/endpoint", methods={"GET"}, name="postback")
     */
    public function postbackAction(Request $request): Response
    {
        if ((!$code = $request->query->get('code')) || (!$state = $request->get('state'))) {
            throw new BadRequestHttpException();
        }

        try {
            $session = $request->getSession();

            if ($session->get(self::SESSION_STATE) !== $state) {
                throw new NotFoundHttpException();
            }

            $session->remove(self::SESSION_STATE);
            $response = $this->client->request('POST', 'https://api.dropbox.com/oauth2/token', [
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode($_ENV['DROPBOX_APP_KEY'] . ':' . $_ENV['DROPBOX_APP_SECRET']),
                ],
                'body' => [
                    'code' => $code,
                    'grant_type' => 'authorization_code',
                    'redirect_uri' => $this->generator->generate(
                        'dropbox_oauth_postback', [], UrlGeneratorInterface::ABSOLUTE_URL,
                    ),
                ],
            ]);

            if (!$parameters = $session->get(self::SESSION_PARAMETERS, [])) {
                throw new BadRequestHttpException('Session was compromised.');
            }

            $_wpnonce = $parameters['_wpnonce'];
            $uri = $parameters['redirect_uri'];
            $content = array_intersect_key($response->toArray(true), array_flip(['access_token', 'refresh_token']));
            $payload = openssl_encrypt(json_encode($content), 'AES-128-ECB', $_wpnonce);


            // Simple template
            ob_start();
            $inputs = compact('payload', '_wpnonce');
            include(dirname(__DIR__, 2) . '/templates/postback.php');
            $content = ob_get_clean();

            return new Response($content);
        } catch (InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage(), $e);
        }
    }

    /**
     * @Route("/refresh", methods={"POST"}, name="refresh_access_token")
     */
    public function refreshAction(Request $request): Response
    {
        $parameters = $this->getParameters($request, ['_wpnonce', 'refresh_token']);

        try {
            $response = $this->client->request('POST', 'https://api.dropboxapi.com/oauth2/token', [
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode($_ENV['DROPBOX_APP_KEY'] . ':' . $_ENV['DROPBOX_APP_SECRET']),
                ],
                'body' => [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $parameters['refresh_token'],
                ]
            ]);


            $content = openssl_encrypt($response->getContent(), 'AES-128-ECB', $parameters['_wpnonce']);

            return new JsonResponse($content, $response->getStatusCode());
        } catch (TransportExceptionInterface | HttpExceptionInterface $e) {
            throw new BadRequestHttpException();
        }
    }

    private function getParameters(
        Request $request,
        array $required_parameters
    ): array {
        $bag = $request->getMethod() === 'POST' ? $request->request : $request->query;
        $parameters = array_filter(array_intersect_key($bag->all(), array_flip($required_parameters)));

        if (count($parameters) !== count($required_parameters)) {
            throw new BadRequestHttpException('Invalid amount of parameters.');
        }

        if (isset($parameters['redirect_uri'])) {
            $url = parse_url($parameters['redirect_uri']);
            if ((strtolower($url['scheme']) ?? null) !== 'https') {
                throw new BadRequestHttpException('Only https redirect URI\'s are allowed.');
            }
        }

        return $parameters;
    }
}
