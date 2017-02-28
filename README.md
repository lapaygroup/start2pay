# Start 2 Pay
PHP SDK для интеграции с сервисом https://start2pay.com  
Описание API Start 2 Pay https://wiki.start2pay.com

# Установка
Для установки можно использовать менеджер пакетов Composer

    composer require lapaygroup/start2pay

# Файл конфигурации
Для работы SDK нужно разместить конфигурационный yaml файл. 
Пример можно посмотреть [тут](https://github.com/lapaygroup/start2pay/blob/master/Examples/config.yml).

**Описание параметров:**
 - auth
   - host
   - username
   - password
   - salt
   - callback_salt
 - display_options
   - language - язык платежной страницы
   - iframe - флаг использования iframe вместо переадресации на платежную страницу Start 2 Pay
   - close_additional_tabs: - флаг закрытия всех дополнительных вкладок
   - device - тип устройства (desktop, mobile)
   - theme - цветовая схема платежной страницы (layout2_white, layout2_black, layout2_dark, layout2_violet)
   - message - Сообщение для заказчика. Such rules, limits, etc
   - description - Описание отображаемое на платежной странице.
   - disable_payment_currency - Данный параметр позволяет при переданом значении true отображать на форме оплаты только одну указанную валюту (currency) которая использовалась при создании платежного контекста
 - available_payment_systems - массивы с алиасами платежных направлений ввода и вывода, которые будут доступны для выбора на платежной странице.
 
# Использование SDK
Для получения платежного контекста (ссылки на платежную страницу или iframe) нужно использовтаь метод **getContext**.  

Пример получения ссылки  
```php
$payInfo['currency'] = 'RUB';
$payInfo['amount'] = '150.00';
$payInfo['invoice'] = '100';
$payInfo['user_id'] = '123456';
$payInfo['selected_payment_system'] = 'bank_cards';

try {
    $API = new \LapayGroup\Start2Pay\API('path/to/config.yml');
    $context = $API->getContext($payInfo);
    
    if (! empty($context['payment_url']) {
        header('Location: '.$context['payment_url']);
    } else {
        // Обработка ошибки получения платежного контекста
        exit;
    }
}

catch(\Exception $e) {
    // Обработка ошибки обмена с Start 2 Pay
}
```
При получении callback запросов на проверку и проведение оплаты от Start 2 Pay также нужно проверять подпись в параметре signature в JSON массиве данных. Для проверки подписи нужно использовать метод **validCallbackSignature**. На вход принимает JSON текст из запроса.  

Пример проверки подписи  
```php
try {
    $API = new \LapayGroup\Start2Pay\API('path/to/config.yml');
    $valid = $API->validCallbackSignature($json);
    
    if ($valid) {
        // Подпись верна - обрабатываем callback
    } else {
        // Обрабатываем ошпше ибку подписи
    }
}

catch(\Exception $e) {
    // Обработка ошибки обмена с Start 2 Pay
}
```
