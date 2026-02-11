<?php
// --- НАСТРОЙКИ ---
// Вставьте сюда ваши реальные данные
define('B24_WEBHOOK_URL', 'https://tugur.bitrix24.ru/rest/15/c9x0qjz9quea1o01/');
define('TG_TOKEN', '8235183293:AAFjAhCwp1Y7OD21MLp8YUTSavMyf45y4Q4');
define('TG_CHAT_ID', '-5206806235');
define('POSITIVE_EVENT_FIELD', 'UF_CRM_1768751320643');
define('NEGATIVE_EVENT_FIELD', 'UF_CRM_1768751944908');
define('EVENT_DATE_FIELD', 'UF_CRM_1770607841259');
// --- КОНЕЦ НАСТРОЕК ---


// === ОТЛАДОЧНАЯ ФУНКЦИЯ ДЛЯ ОТПРАВКИ ЛОГОВ В TELEGRAM ===
function sendDebugToTelegram($data, $title = '') {
    $log = "--- " . (strlen($title) > 0 ? $title : 'DEBUG') . " ---\n";
    $log .= print_r($data, true);

    $params = [
        'chat_id' => TG_CHAT_ID,
        'text' => $log,
    ];
    $url = 'https://api.telegram.org/bot' . TG_TOKEN . '/sendMessage?' . http_build_query($params);
    file_get_contents($url); // Простой способ отправить GET-запрос
}

// Получаем данные от Bitrix24
$input = file_get_contents('php://input');
$request = json_decode($input, true);

// Отправляем сырые данные в Telegram для анализа
sendDebugToTelegram($input, 'RAW Request from B24');

// Проверяем, что данные пришли и это массив
if (!is_array($request) || !isset($request['event'])) {
    sendDebugToTelegram('Request is not a valid JSON or event key is missing.', 'ERROR');
    exit();
}

// Проверяем, что это событие обновления сделки
if ($request['event'] !== 'ONCRMDEALUPDATE') {
    sendDebugToTelegram('Event is not ONCRMDEALUPDATE. Event was: ' . $request['event'], 'Exit Condition');
    exit();
}

$dealId = $request['data']['FIELDS']['ID'];

// Функция для выполнения запросов к API Bitrix24 (остается без изменений)
function executeB24Api($method, $params) {
    $queryUrl = B24_WEBHOOK_URL . $method . '.json';
    $queryData = http_build_query($params);
    $curl = curl_init();
    curl_setopt_array($curl, array(CURLOPT_SSL_VERIFYPEER => 0, CURLOPT_POST => 1, CURLOPT_HEADER => 0, CURLOPT_RETURNTRANSFER => 1, CURLOPT_URL => $queryUrl, CURLOPT_POSTFIELDS => $queryData));
    $result = curl_exec($curl);
    curl_close($curl);
    return json_decode($result, true);
}

// 1. Получаем полную информацию о сделке
$dealInfo = executeB24Api('crm.deal.get', ['id' => $dealId]);
sendDebugToTelegram($dealInfo, 'Deal Info from B24 API');
$deal = $dealInfo['result'];

// 2. Проверяем, заполнены ли поля событий
$positiveEvent = !empty($deal[POSITIVE_EVENT_FIELD]) ? (is_array($deal[POSITIVE_EVENT_FIELD]) ? implode(', ', $deal[POSITIVE_EVENT_FIELD]) : $deal[POSITIVE_EVENT_FIELD]) : '';
$negativeEvent = !empty($deal[NEGATIVE_EVENT_FIELD]) ? (is_array($deal[NEGATIVE_EVENT_FIELD]) ? implode(', ', $deal[NEGATIVE_EVENT_FIELD]) : $deal[NEGATIVE_EVENT_FIELD]) : '';

if (empty($positiveEvent) && empty($negativeEvent)) {
    sendDebugToTelegram('Positive and Negative fields are empty. Exiting.', 'Exit Condition');
    exit(); // Ни одно из полей событий не заполнено, уведомление не нужно.
}

// --- Если скрипт дошел до сюда, он отправит основное сообщение ---
sendDebugToTelegram('Fields are filled. Preparing main message.', 'SUCCESS');

// 3. Собираем информацию для сообщения
$eventType = '';
if (!empty($positiveEvent)) $eventType .= "Положительное: " . (is_string($positiveEvent) ? $positiveEvent : 'Да');
if (!empty($negativeEvent)) $eventType .= (!empty($eventType) ? "\n" : "") . "Отрицательное: " . (is_string($negativeEvent) ? $negativeEvent : 'Да');

$responsibleName = 'Не назначен';
if (!empty($deal['ASSIGNED_BY_ID'])) {
    $userInfo = executeB24Api('user.get', ['ID' => $deal['ASSIGNED_BY_ID']]);
    if (!empty($userInfo['result'][0])) {
        $user = $userInfo['result'][0];
        $responsibleName = $user['NAME'] . ' ' . $user['LAST_NAME'];
    }
}

$companyName = 'Компания не указана';
if (!empty($deal['COMPANY_ID'])) {
    $companyInfo = executeB24Api('crm.company.get', ['id' => $deal['COMPANY_ID']]);
    if (!empty($companyInfo['result']['TITLE'])) {
        $companyName = $companyInfo['result']['TITLE'];
    }
}

$dealDateRaw = !empty($deal[EVENT_DATE_FIELD]) ? $deal[EVENT_DATE_FIELD] : $deal['DATE_MODIFY'];
$dealDate = date('d.m.Y H:i', strtotime($dealDateRaw));

// 4. Формируем текст сообщения
$message = "🔔 **Событие по сделке**\n\n";
$message .= "**Ответственный:** " . $responsibleName . "\n";
$message .= "**Дата события:** " . $dealDate . "\n";
$message .= "**Название сделки:** " . $deal['TITLE'] . "\n";
$message .= "**Сумма:** " . number_format($deal['OPPORTUNITY'], 2, ',', ' ') . ' ' . $deal['CURRENCY_ID'] . "\n";
$message .= "**Компания:** " . $companyName . "\n";
$message .= "**Тип события:**\n" . $eventType;

// 5. Отправляем основное сообщение в Telegram
$telegramApiUrl = 'https://api.telegram.org/bot' . TG_TOKEN . '/sendMessage';
$params = ['chat_id' => TG_CHAT_ID, 'text' => $message, 'parse_mode' => 'Markdown'];
$curl = curl_init();
curl_setopt($curl, CURLOPT_URL, $telegramApiUrl);
curl_setopt($curl, CURLOPT_POST, true);
curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($params));
curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($curl);
curl_close($curl);

sendDebugToTelegram($response, 'Telegram API Response for Main Message');
?>
