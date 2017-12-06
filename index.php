<?php

//! --- Composer Autoload
require_once __DIR__ . '/vendor/autoload.php';
if (!session_id ()) {
    session_start ();
}

//! --- Setup
use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;
use \Slim\App as App;
use \Slim\Views\Twig as View;

$app = new App;
$container = $app->getContainer ();
$container ['view'] = function ($cntnr) { return new View (__DIR__ . '/views'); };

use \Facebook\Facebook as FB;
use Facebook\Exceptions\FacebookResponseException;
use Facebook\Exceptions\FacebookSDKException;

$fb = new FB ([
	'app_id' => 'APP_ID',
	'app_secret' => 'APP_SECRET',
	'default_graph_version' => 'v2.10',
]);
$helper = $fb->getRedirectLoginHelper ();
if (isset ($_GET['state'])) {
	$helper->getPersistentDataHandler ()->set ('state', $_GET['state']);
}

//! --- Routes

//! Index
$app->get('/', function (Request $req, Response $res) {
	return $this->view->render ($res, 'index.html');
});

//! --- Facebook

//! Request OAuth2 URI
$app->get('/auth', function (Request $req, Response $res) use ($helper) {
	$permissions = ['email', 'manage_pages', 'pages_show_list'];
	$loginUrl = $helper->getLoginUrl ($req->getUri ()->getBaseUrl () . '/auth/callback', $permissions);
	return $res->withRedirect ($loginUrl);
});

//! OAuth2 Callback
$app->get('/auth/callback', function (Request $req, Response $res) use ($fb, $helper) {
	try {
		$accessToken = $helper->getAccessToken ();

	} catch(FacebookResponseException $e) {
		$data = [
			'error' => [
				'description' => 'Graph returned an error',
				'message' => $e->getMessage(),
			],
		];
		return $res->withJson($data, 400);

	} catch(FacebookSDKException $e) {
		$data = [
			'error' => [
				'description' => 'Facebook SDK returned an error',
				'message' => $e->getMessage(),
			],
		];
		return $res->withJson($data, 400);
	}

	if (!isset($accessToken)) {
		if ($helper->getError()) {
			$data = [
				'error' => [
					'code' => $helper->getError (),
					'reason' => $helper->getErrorReason (),
					'description' => $helper->getErrorDescription (),
					'message' => $helper->getError (),
				],
			];
			return $res->withJson($data, 401);

		} else {
			$data = [
				'error' => [
					'description' => 'Bad Request',
				],
			];
			return $res->withJson($data, 400);
		}
	}

	$_SESSION ['fb_access_token'] = (string) $accessToken;
	return $res->withRedirect ('/me');
});

//! Middleware, Login
$fbLogin = function (Request $req, Response $res, $next) use ($fb) {
	try {
		$response = $fb->get('/me', $_SESSION ['fb_access_token']);

	} catch(FacebookResponseException $e) {
		$data = [
			'error' => [
				'description' => 'Graph returned an error',
				'message' => $e->getMessage(),
			],
		];
		return $res->withJson($data, 400);

	} catch(FacebookSDKException $e) {
		$data = [
			'error' => [
				'description' => 'Facebook SDK returned an error',
				'message' => $e->getMessage(),
			],
		];
		return $res->withJson($data, 400);
	}

	return $next($req, $res);
};

//! Self User
$app->get('/me', function (Request $req, Response $res) use ($fb) {
	$user = $fb->get ('/me', $_SESSION ['fb_access_token']);

	$data = [
		'accessToken' => $_SESSION ['fb_access_token'],
		'data' => json_decode ($user->getGraphNode ()),
	];
	return $res->withJson($data);
})->add ($fbLogin);

//! List of Pages from Self User
$app->get('/pages', function (Request $req, Response $res) use ($fb) {
	$user = $fb->get ('/me?fields=accounts', $_SESSION ['fb_access_token']);
	$data = [
		'accessToken' => $_SESSION ['fb_access_token'],
		'data' => json_decode ($user->getGraphNode ()),
	];
	return $res->withJson($data);
})->add ($fbLogin);

//! Data from ID Page
$app->get('/page/{page_id}', function (Request $req, Response $res, $i) use ($fb) {
	$user = $fb->get ("/{$i['page_id']}", $_SESSION ['fb_access_token']);
	$data = [
		'accessToken' => $_SESSION ['fb_access_token'],
		'data' => json_decode ($user->getGraphNode ()),
	];
	return $res->withJson($data);
})->add ($fbLogin);

//! --- Run Forest, RUN!
$app->run();
