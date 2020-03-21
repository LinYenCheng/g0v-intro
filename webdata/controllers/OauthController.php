<?php

class OauthController extends Pix_Controller
{
    public function init()
    {
        if ($user_id = Pix_Session::get('user_id') and $user = User::find($user_id)) {
            $this->view->user = $user;
        }
    }

    protected function accessTokenReturn($redirect_uri, $message)
    {
        if ($redirect_uri and strpos($redirect_uri, '?')) {
            $sep = '&';
        } else {
            $sep = '?';
        }

        if ($redirect_uri) {
            $terms = array();
            foreach ($message as $k => $v) {
                $terms[] = urlencode($k) . '=' . urlencode($v);
            }
            return $this->redirect($redirect_uri . $sep . implode('&', $terms));
        } else {
            return $this->json($message);
        }
    }

    public function accesstokenAction()
    {
        $client_id = $_GET['client_id'];
        $code = $_GET['code'];
        $redirect_uri = $_GET['redirect_uri'];
        $client_secret = $_GET['client_secret'];


        if (!$client_id or !$app = OAuthApp::find(intval($client_id))) {
            return $this->accessTokenReturn($redirect_uri, array(
                'error' => 'app_not_found',
                'error_reason' => 'app not found',
            ));
        }
        if (!$this->view->user) {
            return $this->accessTokenReturn($redirect_uri, array(
                'error' => 'need_login',
                'error_reason' => '需要登入 Need to login first',
            ));
        }

        $session_code = OAuthSessionCode::search(array(
            'app_id' => intval($client_id),
            'slack_id' => strval($this->view->user->slack_id),
            'code' => strval($code),
        ))->first();

        if (!$session_code) {
            return $this->accessTokenReturn($redirect_uri, array(
                'error' => 'code_not_found',
                'error_reason' => '找不到代碼 code not found',
            ));
        }

        if ($session_code->getData()->code_challenge_method) {
            // PKCE https://tools.ietf.org/html/rfc7636
            if ($session_code->getData()->code_challenge_method == 'plain') {
                if ($session_code->getData()->code_challenge != $_GET['code_verifier']) {
                    return $this->accessTokenReturn($redirect_uri, array(
                        'error' => 'pkce_error',
                        'error_reason' => 'wrong code_verifier',
                    ));
                }
            } else if ($session_code->getData()->code_challenge_method == 'S256') {
                if (rtrim(strtr(base64_encode(hex2bin(hash('sha256', $_GET['code_verifier']))), '+/', '-_'), '=') != $session_code->getData()->code_challenge) {
                    return $this->accessTokenReturn($redirect_uri, array(
                        'error' => 'pkce_error',
                        'error_reason' => 'wrong code_verifier',
                    ));
                };
            } else {
                return $this->accessTokenReturn($redirect_uri, array(
                    'error' => 'pkce_error',
                    'error_reason' => 'unknown code_challenge_method',
                ));
            }
        }

        $session_code->delete();

        $access_token = OAuthSession::getNewAccessToken();
        OAuthSession::insert(array(
            'access_token' => $access_token,
            'app_id' => intval($client_id),
            'slack_id' => strval($this->view->user->slack_id),
            'created_at' => time(),
            'data' => '{}',
        ));

        return $this->accessTokenReturn($redirect_uri, array(
            'error' => false,
            'access_token' => $access_token,
        ));
    }

    public function authAction()
    {
        $client_id = $_GET['client_id'];
        $redirect_uri = $_GET['redirect_uri'];
        $response_type = $_GET['response_type'];
        $scope = $_GET['scope'];
        $state = $_GET['state'];
        $code_challenge = $_GET['code_challenge'];
        $code_challenge_method = $_GET['code_challenge_method'];

        if (!$redirect_uri) {
            return $this->alert("no redirect_uri", "/oauth");
        }
        if (strpos($redirect_uri, '?')) {
            $sep = '&';
        } else {
            $sep = '?';
        }
        if ($state) {
            $redirect_uri .= $sep . 'state=' . urlencode($state);
            $state = '&';
        }

        if (!$client_id or !$app = OAuthApp::find(intval($client_id))) {
            return $this->redirect($redirect_uri . $sep . 'error=unauthorized_client&error_description=' . urlencode("client_id not found"));
        }

        if ($response_type != 'code') {
            return $this->redirect($redirect_uri . $sep . 'error=unsupported_response_type&error_description=' . urlencode("response_type must be code"));
        }

        if ($app->getData()->redirect_urls) {
            if (!in_array($_GET['redirect_uri'], $app->getData()->redirect_urls)) {
                return $this->redirect($redirect_uri . $sep . 'error=invalid_request&error_description=' . urlencode("redirect_uri is not in redirect urls"));
            }
        }

        if (!$this->view->user) {
            return $this->redirect("/login?next=" . urlencode($_SERVER['REQUEST_URI']));
        }

        // clean old code
        OAuthSessionCode::search(array('app_id' => intval($client_id)))->search("created_at < " . time() - 86400)->delete();

        $code = OAuthSessionCode::insert(array(
            'app_id' => intval($client_id),
            'slack_id' => $this->view->user->slack_id,
            'code' => Helper::uniqid(16),
            'data' => json_encode(array(
                'code_challenge' => strval($code_challenge),
                'code_challenge_method' => strval($code_challenge_method),
            )),
            'created_at' => time(),
        ));


        $redirect_uri .= $sep . 'code=' . urlencode($code->code);
        return $this->redirect($redirect_uri);
    }

    public function indexAction()
    {
        if (!$this->view->user) {
            return $this->alert("需要登入 You need to login first", "/login?next=/oauth");
        }
    }

    public function addappAction()
    {
        if (!$this->view->user) {
            return $this->alert("需要登入 You need to login first", "/login?next=/oauth");
        }
        if (!$_POST['sToken'] or $_POST['sToken'] != Session::getStoken()) {
            return $this->alert("sToken error", "/oauth");
        }

        $client_id = OAuthApp::getNewID();
        $app = OAuthApp::insert(array(
            'client_id' => $client_id,
            'created_at' => time(),
            'created_by' => $this->view->user->slack_id,
            'data' => json_encode(array(
                'name' => strval($_POST['name']),
                'document' => strval($_POST['doc']),
                'client_secret' => Helper::uniqid(32),
            )),
        ));

        return $this->alert("OK", "/oauth/app?id=" . $app->client_id);
    }

    public function updateappAction()
    {
        if (!$this->view->user) {
            return $this->alert("需要登入 You need to login first", "/login?next=/oauth");
        }
        if (!$_POST['sToken'] or $_POST['sToken'] != Session::getStoken()) {
            return $this->alert("sToken error", "/oauth");
        }

        if (!$app = OAuthApp::find($_GET['id'])) {
            return $this->alert("app not found", "/oauth");
        }
        $app->updateData(array(
            'name' => strval($_POST['name']),
            'document' => strval($_POST['doc']),
            'redirect_urls' => array_values(array_filter($_POST['redirect_urls'], 'strlen')),
        ));

        return $this->alert("OK", "/oauth/app?id=" . $app->client_id);
    }

    public function appAction()
    {
        if (!$this->view->user) {
            return $this->alert("需要登入 You need to login first", "/login?next=/oauth");
        }
        if (!$app = OAuthApp::find($_GET['id'])) {
            return $this->alert("App not found", "/oauth");
        }
        if ($app->created_by != $this->view->user->slack_id) {
            return $this->alert("App not found", "/oauth");
        }
        $this->view->app = $app;
    }
}
