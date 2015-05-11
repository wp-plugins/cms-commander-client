<?php

/**
 * Lets you convert OAuth 1 access tokens to OAuth 2 access tokens.  First call {@link
 * OAuth1AccessTokenUpgrader::createOAuth2AccessToken()} to get an OAuth 2 access token.
 * If that succeeds, call {@link OAuth1AccessTokenUpgrader::disableOAuth1AccessToken()}
 * to disable the OAuth 1 access token.
 *
 * <code>
 * use \Dropbox as dbx;
 * $appInfo = dbx\AppInfo::loadFromJsonFile(...);
 * $clientIdentifier = "my-app/1.0";
 * $oauth1AccessToken = dbx\OAuth1AccessToken(...);
 *
 * $upgrader = new dbx\OAuth1AccessTokenUpgrader($appInfo, $clientIdentifier, ...);
 * $oauth2AccessToken = $upgrader->getOAuth2AccessToken($oauth1AccessToken);
 * $upgrader->disableOAuth1AccessToken($oauth1AccessToken);
 * </code>
 */
class Dropbox_OAuth1Upgrader extends Dropbox_AuthBase
{
    /**
     * Given an existing active OAuth 1 access token, make a Dropbox API call to get a new OAuth 2
     * access token that represents the same user and app.
     *
     * See <a href="https://www.dropbox.com/developers/core/docs#oa1-from-oa1">/oauth2/token_from_oauth1</a>.
     *
     * @param Dropbox_OAuth1AccessToken $oauth1AccessToken
     *
     * @return string
     *                The OAuth 2 access token.
     *
     * @throws Dropbox_Exception
     */
    public function createOAuth2AccessToken($oauth1AccessToken)
    {
        Dropbox_OAuth1AccessToken::checkArg("oauth1AccessToken", $oauth1AccessToken);

        $response = self::doPost($oauth1AccessToken, "1/oauth2/token_from_oauth1");

        if ($response->statusCode !== 200) {
            throw Dropbox_RequestUtil::unexpectedStatus($response);
        }

        $parts = Dropbox_RequestUtil::parseResponseJson($response->body);

        if (!array_key_exists('token_type', $parts) or !is_string($parts['token_type'])) {
            throw new Dropbox_Exception_BadResponse("Missing \"token_type\" field.");
        }
        $tokenType = $parts['token_type'];
        if (!array_key_exists('access_token', $parts) or !is_string($parts['access_token'])) {
            throw new Dropbox_Exception_BadResponse("Missing \"access_token\" field.");
        }
        $accessToken = $parts['access_token'];

        if ($tokenType !== "Bearer" && $tokenType !== "bearer") {
            throw new Dropbox_Exception_BadResponse("Unknown \"token_type\"; expecting \"Bearer\", got  "
                .Dropbox_Client::q($tokenType));
        }

        return $accessToken;
    }

    /**
     * Make a Dropbox API call to disable the given OAuth 1 access token.
     *
     * See <a href="https://www.dropbox.com/developers/core/docs#disable-token">/disable_access_token</a>.
     *
     * @param Dropbox_OAuth1AccessToken $oauth1AccessToken
     *
     * @throws Dropbox_Exception
     */
    public function disableOAuth1AccessToken($oauth1AccessToken)
    {
        Dropbox_OAuth1AccessToken::checkArg("oauth1AccessToken", $oauth1AccessToken);

        $response = self::doPost($oauth1AccessToken, "1/disable_access_token");

        if ($response->statusCode !== 200) {
            throw Dropbox_RequestUtil::unexpectedStatus($response);
        }
    }

    /**
     * @param Dropbox_OAuth1AccessToken $oauth1AccessToken
     * @param string                    $path
     *
     * @return Dropbox_HttpResponse
     *
     * @throws Dropbox_Exception
     */
    private function doPost($oauth1AccessToken, $path)
    {
        // Construct the OAuth 1 header.
        $authHeaderValue = "OAuth oauth_signature_method=\"PLAINTEXT\""
            .", oauth_consumer_key=\"".rawurlencode($this->appInfo->getKey())."\""
            .", oauth_token=\"".rawurlencode($oauth1AccessToken->getKey())."\""
            .", oauth_signature=\"".rawurlencode($this->appInfo->getSecret())."&".rawurlencode($oauth1AccessToken->getSecret())."\"";

        return Dropbox_RequestUtil::doPostWithSpecificAuth(
            $this->clientIdentifier, $authHeaderValue, $this->userLocale,
            $this->appInfo->getHost()->getApi(),
            $path,
            null);
    }
}
