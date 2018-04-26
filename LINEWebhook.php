<?php
/*!
 * @file LINEWebhook.php
 * @author Sensu Development Team
 * @date 2018/02/24
 * @brief LINE用Sensuクライアント
 */

require_once __DIR__.'/Config.php';
require_once __DIR__.'/SensuClient.php';
require __DIR__.'/vendor/autoload.php';

class LINEWebhook
{
    /*!
     * @brief SensuプラットフォームAPIクライアント
     */
    private $sensu;

    /*!
     * @brief LINE APIクライアント
     */
    private $line;

    /*!
     * @brief コンストラクタ
     */
    public function __construct()
    {
        $this->sensu = new \SensuDevelopmentTeam\SensuClient(Config::SENSU_PLATFORM_API_KEY);
        $http_client = new \LINE\LINEBot\HTTPClient\CurlHTTPClient(Config::LINE_API_CHANNEL_ACCESS_TOKEN);
        $this->line = new \LINE\LINEBot($http_client, ['channelSecret' => Config::LINE_API_CHANNEL_SECRET]);
    }

    /*!
     * @brief フック
     */
    public function hook()
    {
        // 受信したデータをパース
        $request = file_get_contents('php://input');
        // ヘッダを検査
        if (!isset(getallheaders()['X-Line-Signature']))
        {
            return;
        }
        
        $events = $this->line->parseEventRequest($request, getallheaders()['X-Line-Signature']);
        foreach ($events as $event)
        {
            if (!($event instanceof \LINE\LINEBot\Event\MessageEvent))
            {
                continue;
            }
            if (!($event instanceof \LINE\LINEBot\Event\MessageEvent\TextMessage))
            {
                continue;
            }

            // 接頭辞
            $prefix = './';
            // 本文の先頭が接頭辞でなければ中止
            if (strncmp($event->getText(), $prefix, strlen($prefix)))
            {
                return;
            }

            // 接頭辞を削除
            $command = substr($event->getText(), strlen($prefix), strlen($event->getText()) - strlen($prefix));
            // 命令を分解
            $command = self::getCommandFromText($command);
            
            // 投げ銭コマンド(無効)
            if (isset($command[0]) && $command[0] === 'tip')
            {
                if (isset($command[3]))
                {
                    $command[3] = '';
                }
            }

            // 命令を送信
            $result = $this->sensu->command($event->getUserId(), $command);
            // 表示用メッセージが設定されていなければ内部エラー
            if (!isset($result->message))
            {
                $this->line->replyText($event->getReplyToken(), "内部エラーが発生しました。\nAn internal error occurred.");
                return;
            }
            // 返信
            $this->line->replyText($event->getReplyToken(), $result->message);
        }
    }

    /*!
     * @brief 本文より命令を取得
     * @param $test 本文
     * @return 命令
     */
    private static function getCommandFromText($text)
    {
        $command = htmlspecialchars_decode($text, ENT_NOQUOTES);
        $result = preg_split('/[ \n](?=(?:[^\\"]*\\"[^\\"]*\\")*(?![^\\"]*\\"))/', $command, -1, PREG_SPLIT_NO_EMPTY);
        $result = str_replace('"', '', $result);
        return $result;
    }
}

try
{
    $webhook = new LINEWebhook();
    $webhook->hook();
}
catch (Exception $e)
{
    // Webhook再リクエスト防止の為何もしない
}
