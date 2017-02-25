<?php

namespace Lapaygroup\Start2Pay;

use Symfony\Component\Yaml\Yaml;
use GuzzleHttp\Client;
use LapayGroup\Start2Pay\Exception\ValidConfigAuthException;
use LapayGroup\Start2Pay\Exception\InvalidResponseException;

class Api
{
    private $host = false; //Боевой https://api.start2pay.com
    private $salt = false;
    private $callback_salt = false;
    private $username = false;
    private $password = false;
    private $clientHttp = false;
    private $params = false;

    function __constructor($config_file)
    {
        //Парсим Yaml конфиг с данными для подключения
        $this->params = Yaml::parse(file_get_contents($config_file));

        if (empty($params['host']) || empty($params['username']) || empty($params['password'])
            || empty($params['salt']) || empty($params['callback_salt'])
        ) {
            throw new ValidConfigAuthException('Auth info incorrect', 401);
        }

        $this->host          = $this->params['auth']['host'];
        $this->username      = $this->params['auth']['username'];
        $this->password      = $this->params['auth']['password'];
        $this->salt          = $this->params['auth']['salt'];
        $this->callback_salt = $this->params['auth']['callback_salt'];

        $this->clientHttp = new Client(['base_uri' => $this->host]);
    }

    /**
     * Сортировка массива для подписи
     * @param $array - массив параметров для получения контекста
     * @return bool
     */
    static public function ksortTree(&$array)
    {
        if (!is_array($array)) {
            return false;
        }
        ksort($array);
        foreach ($array as $k => $v) {
            self::ksortTree($array[$k]);
        }
        return true;
    }

    /**
     * Полчаем инфорамцию из headers для Direct аутентификации
     * @param $response - ответ сервера CloudFlare
     * @return array - массив параметров для Direct auth
     */
    static public function get_headers_from_curl_response($response)
    {
        $headers = array();
        foreach (explode("\r\n", $response) as $i => $line) {
            if(empty($line)) continue;
            if ($i === 0) {
                $headers['http_code'] = $line;
            } else {
                $header = explode(': ', $line);
                if(count($header) < 2) continue;
                list ($key, $value) = $header;
                $headers[$key] = $value;
            }
        }
        return $headers;
    }

    /**
     * Проверяет подпись callback запросов от Start 2 Pay
     * @param $json - JSON данные из callback вызова Start 2 Pay
     * @return bool - true - попдись верна, false - не верна
     */
    public function validCallbackSignature($json)
    {
        $params = json_decode($json);
        $signatureS2P = $params['signature'];
        unset($params['signature']);
        $json = json_encode($params);

        $signature = hash('sha256', json_encode($json).$this->callback_salt);

        if($signatureS2P == $signature) {
            return true;
        } else {
            return false;
        }
    }

    public function getContext($data)
    {
        $data['payment_direction'] = 'IN';

        //Заполняем опции отображения из конфиг файла
        if( empty($data['display_options']['language'])
            && !empty($this->params['display_options']['language'])
        ) {
            $data['display_options']['language'] = $this->params['display_options']['language'];
        }

        if( empty($data['display_options']['iframe'])
            && !empty($this->params['display_options']['iframe'])
        ) {
            $data['display_options']['iframe'] = $this->params['display_options']['iframe'];
        }

        if( empty($data['display_options']['theme'])
            && !empty($this->params['display_options']['theme'])
        ) {
            $data['display_options']['theme'] = $this->params['display_options']['theme'];
        }

        if( empty($data['display_options']['device'])
            && !empty($this->params['display_options']['device'])
        ) {
            $data['display_options']['device'] = $this->params['display_options']['device'];
        }

        if( empty($data['display_options']['message'])
            && !empty($this->params['display_options']['message'])
        ) {
            $data['display_options']['message'] = $this->params['display_options']['message'];
        }

        if( empty($data['display_options']['description'])
            && !empty($this->params['display_options']['description'])
        ) {
            $data['display_options']['description'] = $this->params['display_options']['description'];
        }

        if( empty($data['display_options']['close_additional_tabs'])
            && !empty($this->params['display_options']['close_additional_tabs'])
        ) {
            $data['display_options']['close_additional_tabs'] = $this->params['display_options']['close_additional_tabs'];
        }

        if( empty($data['display_options']['disable_payment_currency'])
            && !empty($this->params['display_options']['disable_payment_currency'])
        ) {
            $data['display_options']['disable_payment_currency'] = $this->params['display_options']['disable_payment_currency'];
        }

        //Отправляем запрос на получение контекста в Start 2 Pay
        $result = $this->sendCommand($data, '/pay_context/create', 'in');
        return $result;
    }

    /**
     * Генерирует Header для Digest аутентфикации
     * @param $uri - путь до API функции
     * @return string - header строка для аутентификации
     */
    public function getAuthHeaders($uri, $method = 'POST')
    {
        $response = $this->clientHttp->request($method, $uri);
        $result = $response->getBody();

        $headers = self::get_headers_from_curl_response($result);

        $authRespHeader = explode(',', preg_replace("/^Digest/i", "", $headers['WWW-Authenticate']));
        $authPieces = array();

        foreach ($authRespHeader as &$piece) {
            $piece = trim($piece);
            $piece = explode('=', $piece);
            $authPieces[$piece[0]] = trim($piece[1], '"');
        }

        $nc = str_pad('1', 8, '0', STR_PAD_LEFT);
        $cnonce = '0a4f113b';
        $A1 = md5("{$this->username}:{$authPieces['realm']}:{$this->password}");
        $A2 = md5("{$method}:{$uri}");
        $authPieces['response'] = md5("{$A1}:{$authPieces['nonce']}:{$nc}:{$cnonce}:{$authPieces['qop']}:${A2}");

        if(empty($authPieces['opaque'])) $authPieces['opaque'] = '';
        if(empty($authPieces['algorithm'])) $authPieces['algorithm'] = '';

        $digestHeader = "Digest username=\"{$this->username}\", realm=\"{$authPieces['realm']}\", nonce=\"{$authPieces['nonce']}\", uri=\"{$uri}\", cnonce=\"{$cnonce}\", nc={$nc}, qop=\"{$authPieces['qop']}\", response=\"{$authPieces['response']}\", opaque=\"{$authPieces['opaque']}\", algorithm=\"{$authPieces['algorithm']}\"";

        return $digestHeader;
    }


    /**
     * @param $data
     * @param $uri
     * @return array|mixed
     */
    public function sendCommand($data, $uri, $direction = 'in')
    {
        //Добавляем доступные платежные системы на форму Start 2 Pay
        if (! empty($this->params['available_payment_systems'][$direction])) {
            if (empty($data['available_payment_systems']))
                $data['available_payment_systems'] = [];

            $data['available_payment_systems'] = array_merge($data['available_payment_systems'], $this->params['available_payment_systems']['in']);
        }

        self::ksortTree($data);
        $data['signature'] = hash('sha256', json_encode($data).$this->salt);
        $digestHeader = $this->getAuthHeaders($uri);

        $response = $this->clientHttp
                         ->request( 'POST',
                                    $uri,
                                    ['headers' => ['Authorization' => $digestHeader]],
                                    ['form_params' => $data]
                                  );

        $result = $response->getBody();

        $resArr = explode("\n\r", $result);

        if(!empty($resArr) && count($resArr) > 1) {
            $resArr = json_decode(array_pop($resArr));
            return $resArr;
        } else {
            throw new InvalidResponseException('Неверный ответ от сервера Start 2 Pay', 402);
        }
    }
}
