<?php
require 'vendor/autoload.php';

use GuzzleHttp\Client;
//use GuzzleHttp\Cookie\CookieJar;
use Symfony\Component\DomCrawler\Crawler;

//Создаём константу такскома, потому что будем часто использовать
define('BASE_URI', 'https://taxcom.ru');
//Увеличиваем лимит ожидания выполнения скрипта, чтобы не было ошибок
set_time_limit(5600);
if (!file_exists('./logs')) mkdir('./logs');
chdir(__DIR__ . '/logs');
if (!file_exists('cronIteration.txt')) file_put_contents(__DIR__ . '/logs/cronIteration.txt', 0);
$iteration = (int)file_get_contents('cronIteration.txt');
echo $iteration;
if (file_exists(__DIR__ . '/logs/errors.log')) {
    $err = json_decode(file_get_contents(__DIR__ . '/logs/errors.log'), JSON_OBJECT_AS_ARRAY);
    if(is_array($err)){
        $err = end($err);
        if ($err['iteration'] == $iteration && date('H', file(__DIR__ . '/logs/errors.log')) >= date('H')) {
            sleep(3600);
        }
    }
    else{
        echo 'Ошибка сбора ошибок';
    }
}

//Проверяется дата последней модификации файла cronIteration (если больше недели), если понадобится
//if (date('N')<6 || date('W')>date('W', filemtime(__DIR__.'/logs/cronIteration.txt'))&&date('N')<6) {

//Проверяется день недели (не суббота и не воскресенье)
if (date('N') < 6) {
    //Проверяется рабочее время парсера (с 13:00 до 16:00)
    if (date('H') >= 13 && date('H') <= 16) {
        //Проверяем на наличие папки assets, если её нет - создаём
        if (!file_exists(__DIR__ . '/assets')) mkdir(__DIR__ . '/assets', 0777);
        chdir(__DIR__ . '/assets');
        //Проверка файла ссылок на отчётность и эл.подписи, а так же проверям как давно был создан файл, если больше месяца - парсим
        if (!file_exists('links.json') || (string)date('Y-m') > (string)date('Y-m', filemtime('links.json'))) {
            ParseClasses();
        }

        //Проверяем файл "города" на существование и дату создания
        if (!file_exists('cities.json') || (string)date('Y-m') > (string)date('Y-m', filemtime('cities.json'))) {
            //Парсим города и их id
            $data = citiesParser();
            if ($data == false) return;
            //Сохраняем города
            file_put_contents('cities.json', json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            $citiesCount = count($data);
            if ($iteration > $citiesCount) $iteration = -1;
            if ($iteration != -1) {
                if (!file_exists('./cities')) mkdir('./cities');
                chdir('./cities');
                //Проходимся по городам
                foreach ($data as $k => $city) {
                    if ($k == $iteration) {
                        //Проверяем на существование папок с названиями городов
                        if (!file_exists('./' . $city['path'])) mkdir('./' . $city['path'], 0777);
                        chdir('./' . $city['path']);
                        //Собираем массив файлов в папке города
                        $files = glob('./*');
                        if (empty($files)) {
                            //Парсим, если нет элементов
                            Parse($city['id'], json_decode(file_get_contents(__DIR__ . '/assets/links.json'), JSON_OBJECT_AS_ARRAY));
                        } else {
                            //Проверяем каждый элемент на "устаревание" и парсим 3 файла, если хоть один устарел
                            foreach ($files as $filename) {
                                if ((string)date('Y-m') > (string)date('Y-m', filemtime($filename))) {
                                    //Debug(json_decode(file_get_contents(__DIR__.'/assets/links.json'), JSON_OBJECT_AS_ARRAY));
                                    Parse($city['id'], json_decode(file_get_contents(__DIR__ . '/assets/links.json'), JSON_OBJECT_AS_ARRAY));
                                }
                            }
                        }
                        chdir(__DIR__ . '/assets/cities');
                    }
                }
                chdir(__DIR__ . '/assets');
            } else {
                sleep(3600);
            }
        } else {
            $data = json_decode(file_get_contents('cities.json'), JSON_OBJECT_AS_ARRAY);

            $citiesCount = count($data);
            if ($iteration > $citiesCount) $iteration = -1;
            if ($iteration != -1) {
                if (!file_exists('./cities')) mkdir('./cities');
                chdir('./cities');
                foreach ($data as $k => $city) {
                    if ($k == $iteration) {
                        if (!file_exists('./' . $city['path'])) mkdir('./' . $city['path'], 0777);
                        chdir('./' . $city['path']);
                        $files = glob('./*');
                        if (empty($files)) {
                            //Debug(json_decode(file_get_contents(__DIR__.'/assets/links.json'), JSON_OBJECT_AS_ARRAY));
                            Parse($city['id'], json_decode(file_get_contents(__DIR__ . '/assets/links.json'), JSON_OBJECT_AS_ARRAY));
                        } else {
                            foreach ($files as $filename) {
                                if ((string)date('Y-m') > (string)date('Y-m', filemtime($filename))) {
                                    //Debug(json_decode(file_get_contents(__DIR__.'/assets/links.json'), JSON_OBJECT_AS_ARRAY));
                                    Parse($city['id'], json_decode(file_get_contents(__DIR__ . '/assets/links.json'), JSON_OBJECT_AS_ARRAY));
                                }
                            }
                        }
                        chdir(__DIR__ . '/assets/cities');
                    }
                }
                chdir(__DIR__ . '/assets');
            } else {
                sleep(3600);
            }
        }
        chdir(__DIR__);
        file_put_contents(__DIR__ . '/logs/cronIteration.txt', ++$iteration);
        //sleep(95);
    }
}

/*

Коды ошибок:
1 - citiesParser выдал (список городов не парсится)
2 - Parser выдал (города не парсятся)
2.1 - Parser выдал (отчёность не парсится)
2.2 - Parser выдал (эл.подписи не парсятяся)
3 - ParseClasses выдал (не парсятся "папки" ака "категории услуг")

*/

function citiesParser()
{
    try {
        //Отправляем запрос на такском
        $client = new Client(['base_uri' => BASE_URI]);
        $response = $client->request('GET');
        if ($response->getStatusCode() == 200) {
            //Сохраняем контент в переменную
            $body = $response->getBody()->getContents();
            $crawler = new Crawler((string)$body);
            //Выбираем необходимую часть страницы
            $cities = $crawler->filter('body > header > div#regionSelector')->each(function (Crawler $node, $i) {
                //Что странно следующий фильтер не работает выше, поэтому создаём ещё один цикл, где сохраняем id и имя города
                $city = $node->filter('div.regionBlock>a.region')->each(function (Crawler $some, $j) {
                    $city['id'] = (string)$some->attr('data-id');
                    $city['name'] = (string)$some->text();
                    $city['path'] = translate($city['name']);
                    return $city;
                });
                return $city;
            });
            return $cities[0];
        } else {
            $e = array('iteration' => $GLOBALS["iteration"], 'errorCode' => 3, 'error' => 'Нет соединиения с сайтом');
            file_put_contents(__DIR__ . '/logs/errors.log', json_encode($e, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ',', FILE_APPEND);
            $GLOBALS["iteration"] -= 1;
            return false;
        }
    } catch (Exception $e) {
        $error = array('iteration' => $GLOBALS["iteration"], 'errorCode' => 1, 'error' => $e);
        file_put_contents(__DIR__ . '/logs/errors.log', json_encode($error, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ',', FILE_APPEND);
        $GLOBALS["iteration"] -= 1;
        return false;
    }
}

function Parse($cityId, $links)
{
    try {
        $client = new Client(['cookies' => true]);
        foreach ($links as $k => $element) {
            switch ($k) {
                case 0:
                    try {
                        //Запрос на сервер с сылкой на определённую вкладку
                        $response = $client->request('GET', BASE_URI.$element['link'].'?action=setRegion&id='.$cityId, ['headers'=>array('Cache-Control'=>'no-cache')]);
                        if ($response->getStatusCode() == 200) {
                            $body = $response->getBody();
                            //Берём часть страницы со скриптом
                            $script = strstr(strstr((string)$body, 'var masterOtchetnost = new MasterOtchetnost({'), '</script>', true);
                            //Выделяем json продуктов (убераем лишнии знаки и меняем ' на ", чтобы работало всё как json)
                            $content = str_replace("'", '"', str_replace('tariffs: ', '', strstr(strstr($script, 'tariffs: '), '}}},', true) . '}}}'));
                            file_put_contents($element['file_name'], $content);
                            //Выделяем json показываемых кнопок (проделываем аналогичное, как ранее с продуктами, но в конце убираем запятую и табуляцию)
                            $buttons = substr(str_replace("'", '"', str_replace('filter: ', '', strstr(strstr($script, 'filter:'), 'showPeriods:', true))), 0, -14);
                            file_put_contents('filters_ac.json', $buttons);
                            $citiesScript = StringCleaner(trim(str_replace('currentRegion:', '', (strstr(strstr(strstr(strstr((string)$body, 'var regionSelector = new RegionSelector({'), '</script>', true), 'currentRegion:'), '});', true))), "\t\n\r\0\x0B"));
                            $logs = array('cityId' => $cityId, 'site' => $element['link'], 'realCityId' => $citiesScript, 'connectionResult' => $response->getStatusCode());
                            file_put_contents(__DIR__ . '/logs/parser_logs.log', json_encode($logs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ',', FILE_APPEND);
                        } else {
                            $e = array('iteration' => $GLOBALS["iteration"], 'errorCode' => "2.1", 'error' => 'Нет соединиения с сайтом', 'cityId' => $cityId);
                            file_put_contents(__DIR__ . '/logs/errors.log', json_encode($e, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ',', FILE_APPEND);
                            $GLOBALS["iteration"] -= 1;
                        }
                    } catch (Exception $e) {
                        $error = array('iteration' => $GLOBALS["iteration"], 'errorCode' => "2.1", 'error' => (string)$e, 'cityId' => $cityId);
                        file_put_contents(__DIR__ . '/logs/errors.log', json_encode($error, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ',', FILE_APPEND);
                        $GLOBALS["iteration"] -= 1;
                    }
                    sleep(30);
                    break;
                case 1:
                    try {
                        //Создаём массив, в котором будем хранить данные
                        $data = [];
                        //Отправляем запрос
                        $response = $client->request('GET', BASE_URI.$element['link'].'?action=setRegion&id='.$cityId, ['headers'=>array('Cache-Control'=>'no-cache')]);
                        if ($response->getStatusCode() == 200) {
                            $body = $response->getBody()->getContents();
                            $crawler = new Crawler((string)$body);
                            //Выбираем необходимую часть страницы и сохраняем необходимые данные в массив
                            $data = $crawler->filter('div.tariffsUc > .container > .tariffsUcTabContent > .row > .tariffsUcTabContent__tariff')->each(function (Crawler $node, $i) {
                                $data['id'] = $i;
                                $data['name'] = (string)StringCleaner($node->filter('.tariffUc__title')->text('Default text content', false));
                                $data['description'] = (string)StringCleaner($node->filter('.tariffUc__desc')->text('Default text content', false));
                                $data['price'] = (int)$node->filter('.tariffUc__switch > input')->attr('data-price');
                                $data['fast_price'] = (int)$node->filter('.tariffUc__switch > input')->eq(1)->attr('data-price');
                                $data['link'] = (string)$node->filter('.tariffUc__switch > input')->attr('data-link');
                                $data['link_fast'] = (string)$node->filter('.tariffUc__switch > input')->eq(1)->attr('data-link');
                                return $data;
                            });
                            $data = ParseESFilters($cityId, $data);
                            $citiesScript = StringCleaner(trim(str_replace('currentRegion:', '', (strstr(strstr(strstr(strstr((string)$body, 'var regionSelector = new RegionSelector({'), '</script>', true), 'currentRegion:'), '});', true))), "\t\n\r\0\x0B"));
                            $logs = array('cityId' => $cityId, 'site' => $element['link'], 'realCityId' => $citiesScript, 'connectionResult' => $response->getStatusCode());
                            file_put_contents(__DIR__ . '/logs/parser_logs.log', json_encode($logs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ',', FILE_APPEND);
                            //Сохраняем
                            file_put_contents('electronic_signatures.json', json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                        } else {
                            $e = array('iteration' => $GLOBALS["iteration"], 'errorCode' => "2.2", 'error' => 'Нет соединиения с сайтом', 'cityId' => $cityId);
                            file_put_contents(__DIR__ . '/logs/errors.log', json_encode($e, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ',', FILE_APPEND);
                            $GLOBALS["iteration"] -= 1;
                        }
                    } catch (Exception $e) {
                        $error = array('iteration' => $GLOBALS["iteration"], 'errorCode' => "2.2", 'error' => (string)$e, 'cityId' => $cityId);
                        file_put_contents(__DIR__ . '/logs/errors.log', json_encode($error, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ',', FILE_APPEND);
                        $GLOBALS["iteration"] -= 1;
                    }
                    sleep(30);
                    break;
                default:
                    break;
            }
        }
    } catch (Exception $e) {
        file_put_contents(__DIR__ . 'logs/errors.log', json_encode((string)$e, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) . ',', FILE_APPEND);
    }
}

function ParseClasses()
{
    try {
        //Делаем запрос на такском, где мы видим все "классы" услуг
        $client = new Client(['base_uri' => BASE_URI]);
        $response = $client->request('GET', '/products/');
        if ($response->getStatusCode() == 200) {
            //Сохраняем в переменную контент страницы и проходимся по ним DomCrawler`ом, отфильтровав только ту часть, где находятся "классы"
            //each в нашем случае работает, как foreach, а функция сохраняет в определённый момент итерации в $node часть страницы, в нашем случае один из классов, а под $i интерацию
            $body = $response->getBody()->getContents();
            $crawler = new Crawler((string)$body);
            $links = $crawler->filter('.middle > section > .container > .row > div')
                ->each(function (Crawler $node, $i) {
                    //Нам нужны отчётность и электронная подпись, что проходятся под итерацией 0 и 1 соответственно
                    if ($i == 0 || $i == 1) {
                        //Сохраняем имя
                        $links['name'] = $node->text('a>div');
                        //сохраняем путь к картинке, как и картинку
                        $links['img_path'] = SavePhoto($node->filter('img')->attr('src'), BASE_URI);
                        //нам нужны все продукты из электронных подписей, поэтому добавляем all/ к ссылке, где электронные подписи
                        switch ($i) {
                            case 0:
                                $links['link'] = (string)$node->filter('a')->attr('href');
                                $links['file_name'] = (string)'accounting.json';
                                $links['buttons_file_name'] = (string)'accouting_buttons.json';
                                break;
                            case 1:
                                $links['link'] = (string)$node->filter('a')->attr('href') . 'all/';
                                $links['file_name'] = (string)'electronic_signatures.json';
                                break;
                            default:
                                break;
                        }
                        return $links;
                    }
                });
            foreach ($links as $k => $element) {
                //Если ссылок, картинок и остального нет - удаляем элемент
                if (empty($element)) {
                    unset($links[$k]);
                }
            }
            file_put_contents('links.json', json_encode($links, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $e = array('iteration' => $GLOBALS["iteration"], 'errorCode' => 3, 'error' => 'Нет соединиения с сайтом');
            file_put_contents(__DIR__ . '/logs/errors.log', json_encode($e, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ',', FILE_APPEND);
            $GLOBALS["iteration"] -= 1;
        }
    } catch (Exception $e) {
        $error = array('iteration' => $GLOBALS["iteration"], 'errorCode' => 3, 'error' => $e);
        file_put_contents(__DIR__ . '/logs/errors.log', json_encode($error, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ',', FILE_APPEND);
        $GLOBALS["iteration"] -= 1;
    }
}
function ParseESFilters($cityId, $products){
    $data = [];
    $client = new Client();
    $response = $client->request('GET', BASE_URI.'/centr/'.'?action=setRegion&id='.$cityId, ['headers'=>array('Cache-Control'=>'no-cache')]);
    if($response->getStatusCode()==200){
        $body = $response->getBody();
        $crawler = new Crawler((string)$body);
        $data = $crawler->filter('#tariffsUc > .tariffsUc >.tariffsUcNavTab > .container > ul > li')->each(function (Crawler $node, $i){
            $data['name']=trim((string)$node->filter('a')->text('Default text content', false));
            $data['id']=str_replace('#','', (string)$node->filter('a')->attr('href'));
            return $data;
        });
        foreach($data as $k => $value){
            $data[$k]['products'] = $crawler->filter('#tariffsUc > .tariffsUc > .container > .tariffsUcTabContent > #' . $value['id'] . '> div > .row > .tariffsUcTabContent__tariff')
            ->each(function(Crawler $node, $i){
                $value['products']['name']=trim($node->filter('.tariffUc>.tariffUc__top>.tariffUc__title')
                ->text('Default text content', false));
                return array('name'=>$value['products']['name']);
            });
        }
        foreach($products as $k=>$v){
            foreach($data as $keyData=>$valueData){
                foreach($valueData['products'] as $keyProducts => $vProducts){
                    if($v['name']==$vProducts['name']){
                        $products[$k]['filter']=$valueData['name'];
                    }
                }
            }
        }
        file_put_contents('filters_es.json', json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $products;
    }
    return $products;
}

function translate($text)
{
    $converter = array(
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd',
        'е' => 'e', 'ё' => 'e', 'ж' => 'zh', 'з' => 'z', 'и' => 'i',
        'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n',
        'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't',
        'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'c', 'ч' => 'ch',
        'ш' => 'sh', 'щ' => 'sch', 'ь' => '', 'ы' => 'y', 'ъ' => '',
        'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
    );
    $text = mb_strtolower($text);
    $text = strtr($text, $converter);
    $text = str_replace(array(' ', ','), '_', $text);
    return $text;
}

function SavePhoto($link, $uri)
{
    $name = str_replace('img/', '', $link);
    $path = str_replace('img/', 'images/', $link);
    $link = $uri . $link;
    //Проверка на наличие папки images
    if (!file_exists('./images')) mkdir('./images', 0777);
    chdir('./images');
    //Проверка на наличие файла картинки, если нет - сохраняем
    if (!file_exists($name)) {
        copy($link, $name);
    } elseif ((string)date('Y-m') > (string)date('Y-m', filemtime($name))) {
        copy($link, $name);
    }  //Ежемесячное сохранение картинки
    chdir('..');
    return $path;
}

//Эта функция удаляет все ненужные символы, которые я обнаружил в результате прошлых "версий" парсера
function StringCleaner($str)
{
    return ltrim(rtrim(preg_replace('!\s++!u', ' ', trim($str, '\x20\t\n\0\v')), '  '), '  ');
}

//Просто вынес print_r в функцию для удобства
function Debug($data)
{
    echo '<pre>';
    print_r($data);
    echo '</pre>';
}