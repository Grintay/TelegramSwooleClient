<?php

namespace TelegramSwooleClient;

use danog\MadelineProto;

class Client
{

    public $MadelineProto;
    private $sessionFile;

    /**
     * Client constructor.
     */
    public function __construct($sessionFile)
    {
        $this->config = Config::getInstance()->get('telegram');

        if (empty($this->config['connection_settings']['all']['proxy_extra']['address'])) {
            $this->config['connection_settings']['all']['proxy'] = '\Socket';
            $this->config['connection_settings']['all']['proxy_extra'] = [];
        }

        $this->sessionFile = $sessionFile;
        $this->connect();
    }

    public function connect()
    {
        //При каждой инициализации настройки обновляются из массива $config
        echo PHP_EOL . 'Starting telegram client ...' . PHP_EOL;
        $time = microtime(true);
        $this->MadelineProto = new MadelineProto\API($this->sessionFile, $this->config);
        $this->MadelineProto->start();
        $this->MadelineProto->async(true);
        $this->MadelineProto->loop();
        $time = round(microtime(true) - $time, 3);
        echo PHP_EOL . "Client started: $time sec" . PHP_EOL;
    }

    /**
     * Получает последние сообщения из указанных каналов
     *
     * @param array $data
     * <pre>
     * [
     *     'peer'          => '',
     *     'offset_id'     => 0, // (optional)
     *     'offset_date'   => 0, // (optional)
     *     'add_offset'    => 0, // (optional)
     *     'limit'         => 0, // (optional)
     *     'max_id'        => 0, // (optional)
     *     'min_id'        => 0, // (optional)
     *     'hash'          => 0, // (optional)
     * ]
     * </pre>
     * @return array
     * @throws \Throwable
     */
    public function getHistory($data):array
    {
        $data = array_merge([
            'peer' => '',
            'offset_id' => 0,
            'offset_date' => 0,
            'add_offset' => 0,
            'limit' => 0,
            'max_id' => 0,
            'min_id' => 0,
            'hash' => 0,
        ], $data);


        return \Amp\Promise\wait($this->MadelineProto->messages->getHistory($data));
    }

    /**
     * Пересылает сообщения без ссылки на оригинал
     *
     * @param array $data
     * <pre>
     * [
     *  'from_peer' => '',
     *  'to_peer'   => '',
     *  'id'        => [], //Id сообщения, или нескольких сообщений
     * ]
     * </pre>
     * @return array
     * @throws \Throwable
     */
    public function copyMessages($data): array
    {

        $data = array_merge([
            'from_peer' => '',
            'to_peer' => '',
            'id' => [],
        ], $data);

        $response = \Amp\Promise\wait($this->MadelineProto->channels->getMessages([
            'channel' => $data['from_peer'],
            'id' => $data['id'],
        ]));
        $result = [];
        if (!$response || !is_array($response) || !array_key_exists('messages', $response)) {
            return $result;
        }

        foreach ($response['messages'] as $message) {
            usleep(mt_rand(300, 2000) * 1000);
            $messageData = [
                'message' => $message['message'] ?? '',
                'peer' => $data['to_peer'],
                'entities' => $message['entities'] ?? [],
            ];
            if (
            static::hasMedia($message, false)
            ) {
                $messageData['media'] = $message; //MadelineProto сама достанет все media из сообщения.
                $result[] = $this->sendMedia($messageData);
            } else {
                $result[] = $this->sendMessage($messageData);
            }
        }

        return $result;

    }

    /**
     * @param array $data
     * <pre>
     * [
     *  'q'             => '',  //Поисковый запрос
     *  'offset_id'     => 0,   // (optional)
     *  'offset_date'   => 0,   // (optional)
     *  'limit'         => 10,  // (optional)
     * ]
     * </pre>
     * @return array
     * @throws \Throwable
     */
    public function searchGlobal(array $data)
    {
        $data = array_merge([
            'q' => '',
            'offset_id' => 0,
            'offset_date' => 0,
            'limit' => 10,
        ], $data);
        return \Amp\Promise\wait($this->MadelineProto->messages->searchGlobal($data));
    }

    /**
     * @param array $data
     * <pre>
     * [
     *  'peer'              => '',
     *  'message'           => '',      // Текст сообщения
     *  'reply_to_msg_id'   => 0,       // (optional)
     *  'parse_mode'        => 'HTML',  // (optional)
     * ]
     * </pre>
     * @return array
     * @throws \Throwable
     */
    public function sendMessage($data = [])
    {
        $data = array_merge([
            'peer' => '',
            'message' => '',
            'reply_to_msg_id' => 0,
            'parse_mode' => 'HTML',
        ], $data);

        return \Amp\Promise\wait($this->MadelineProto->messages->sendMessage($data));
    }

    /**
     * @param array $data
     * <pre>
     * [
     *  'peer'              => '',
     *  'message'           => '',      // Текст сообщения,
     *  'media'             => [],      // MessageMedia, Update, Message or InputMedia
     *  'reply_to_msg_id'   => 0,       // (optional)
     *  'parse_mode'        => 'HTML',  // (optional)
     * ]
     * </pre>
     * @return array
     * @throws \Throwable
     */
    public function sendMedia($data = [])
    {
        $data = array_merge([
            'peer' => '',
            'message' => '',
            'media' => [],
            'reply_to_msg_id' => 0,
            'parse_mode' => 'HTML',
        ], $data);

        return \Amp\Promise\wait($this->MadelineProto->messages->sendMedia($data));
    }


    /**
     * Загружает медиафайл из указанного сообщения во временный файл
     *
     * Внимание! Необходимо самостоятельно удалять временные файлы после их использования
     * @param $data
     * @return array
     * @throws \Throwable
     */
    public function getMedia($data)
    {
        $data = array_merge([
            'channel' => '',
            'id' => [0],
            'message' => [],
            'size_limit' => 0,
        ], $data);


        if (!$data['message']) {
            $response = \Amp\Promise\wait($this->MadelineProto->channels->getMessages($data));
            $message = $response['messages'][0];
        } else {
            $message = $data['message'];
        }

        if (!static::hasMedia($message)) {
            throw new \UnexpectedValueException('Message has no media');
        }

        $info = $this->MadelineProto->get_download_info($message);

        if ($data['size_limit']) {
            if ($info['size'] > $data['size_limit']) {
                throw new \OutOfRangeException(
                    "Media exceeds size limit. Size: {$info['size']} bytes; limit: {$data['size_limit']} bytes"
                );
            }
        }

        $file = tempnam(sys_get_temp_dir(), 'telegram_media_');
        $this->MadelineProto->download_to_file($message, $file);

        return [
            'headers' => [
                ['Content-Length', $info['size']],
                ['Content-Type', $info['mime']]
            ],
            'file' => $file,
        ];
    }

    /**
     * Загружает превью медиафайла из указанного сообщения во временный файл
     *
     * Внимание! Необходимо самостоятельно удалять временные файлы после их использования
     * @param array $data
     * @return array
     * @throws \Throwable
     */
    public function getMediaPreview(array $data)
    {
        $data = array_merge([
            'channel' => '',
            'id' => [0],
            'message' => [],
        ], $data);

        if (!$data['message']) {
            $response = \Amp\Promise\wait($this->MadelineProto->channels->getMessages($data));
            $message = $response['messages'][0];
        } else {
            $message = $data['message'];
        }

        if (!static::hasMedia($message)) {
            throw new \UnexpectedValueException('Message has no media');
        }

        $media = $message['media'][array_key_last($message['media'])];
        switch (true) {
            case isset($media['sizes']):
                $thumb = $media['sizes'][array_key_last($media['sizes'])];
                break;
            case isset($media['thumb']['size']):
                $thumb = $media['thumb'];
                break;
            case !empty($media['thumbs']):
                $thumb = $media['thumbs'][array_key_last($media['thumbs'])];
                break;
            case isset($media['photo']['sizes']):
                $thumb = $media['photo']['sizes'][array_key_last($media['photo']['sizes'])];
                break;
            default:
                throw new \UnexpectedValueException('Message has no preview');

        }
        $info = $this->MadelineProto->get_download_info($thumb);
        $file = tempnam(sys_get_temp_dir(), 'telegram_media_preview_');
        $this->MadelineProto->download_to_file($thumb, $file);

        return [
            'headers' => [
                ['Content-Length', $info['size']],
                ['Content-Type', $info['mime']],
            ],
            'file' => $file,
        ];
    }

    /**
     * Проверяет есть ли подходящие медиа у сообщения
     * @param array $message
     * @return bool
     */
    private static function hasMedia($message = [], $useWebPage = true)
    {
        $media = $message['media'] ?? [];
        if (empty($media['_'])) {
            return false;
        }
        if ($media['_'] == 'messageMediaWebPage') {
            return $useWebPage;
        }
        return true;
    }

}
