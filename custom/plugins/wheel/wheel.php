<?php

namespace Ofey\Logan22\component\plugins\wheel;

use DateTime;
use Ofey\Logan22\component\alert\board;
use Ofey\Logan22\component\image\client_icon;
use Ofey\Logan22\component\redirect;
use Ofey\Logan22\component\sphere\server;
use Ofey\Logan22\component\sphere\type;
use Ofey\Logan22\component\time\time;
use Ofey\Logan22\model\admin\validation;
use Ofey\Logan22\model\db\sql;
use Ofey\Logan22\model\user\user;
use Ofey\Logan22\template\tpl;

class wheel
{

    private static $COOLDOWN_SECONDS = 5; // Время задержки в секундах

    public function saveWheel()
    {
        validation::user_protection("admin");
        $object_id = null;
        $wheelName = $_POST['wheel_name'] ?? board::error("Введите название вашего колеса");
        $wheelCost = (float)$_POST['wheel_cost'] ?? board::error("Введите стоимость прокрутки");
        $wheelType = $_POST['type'] ?? board::error("Нет типа действия");
        if($wheelType == "update"){
            $object_id = (int)$_POST['object_id'] ?? board::error("Не удалось индикатор рулетки");
        }
        //Проверка wheelCost на int или float и чтоб был больше нуля
        if (!is_numeric($wheelCost)) {
            board::error("Цена прокрутки должна быть числом");
        }
        if ($wheelCost < 0) {
            board::error("Цена прокрутки должна быть больше 0");
        }
        //Проверка wheelName на длину и чтоб был больше 3 символов
        if (mb_strlen($wheelName) > 20) {
            board::error("Длина названия не может быть больше 20 символов");
        }
        if (mb_strlen($wheelName) < 3) {
            board::error("Длина названия должна быть больше 3 символов");
        }

        // Массив для хранения преобразованных данных
        $transformedData  = [];
        $totalProbability = 0.00;
        $data             = $_POST;
        if ( ! isset($data['item']) or $data['item'] == "") {
            board::error("Вы не заполнили массив с ID предметами");
        }

        // Получаем количество элементов в массиве 'item'
        $itemCount = count($data['item']);
        //Если больше 20 элементов, то ошибка
        if ($itemCount > 20) {
            board::error("Массив с данными для создания рулетки содержит больше 20 элементов");
        }

        // Проходим по каждому элементу
        for ($i = 0; $i < $itemCount; $i++) {
            $numItem     = $i + 1;
            $itemId      = $data['item'][$i] ?? null;
            $enchant     = $data['enchant'][$i] ?? 0;
            $count       = $data['count'][$i] ?? 1;
            $countMin    = $data['count_min'][$i] ?? null;
            $countMax    = $data['count_max'][$i] ?? null;
            $probability = isset($data['probability'][$i]) ? (float)$data['probability'][$i] : null;
            if ( ! $itemId) {
                board::error("Вы не заполнили ID предмета (предмет №{$numItem})");
            }
            $countType = $data['way'][$i] ?? board::error("Вы не заполнили способ кол-ва (предмет №{$numItem})");

            if ( ! $probability) {
                board::error("Вы не указали процент выигрыша (предмет №{$numItem})");
            }

            $totalProbability = round($totalProbability, 2);
            $totalProbability += $probability;
            if ($probability < 0) {
                board::error("Процент выигрыша должен быть больше 0");
            }

            $itemData = client_icon::get_item_info($itemId);
            if ($itemData == null) {
                board::error("Не удалось получить информацию о предмете (предмет #{$numItem})");
            }

            $transformedData[] = [
              'num'         => (int)$numItem,
              'item_id'     => (int)$itemId,
              'enchant'     => (int)$enchant,
              'count_type'  => (int)$countType,
              'count'       => (int)$count,
              'count_min'   => (int)$countMin,
              'count_max'   => (int)$countMax,
              'probability' => (float)$probability,
            ];
        }

        if ($totalProbability != 100.00) {
            board::error("Общий процент выигрыша должен быть 100%, у Вас {$totalProbability}%.");
        }

        $data = [
          'object_id' =>  $object_id,
          'wheel_name' => $wheelName,
          'items'      => $transformedData,
          'type'       => $wheelType,
          'cost'       => $wheelCost,
        ];

        $response = server::send(type::GAME_WHEEL_SAVE, $data)->show()->getResponse();
        if ($response == null) {
            board::error("Ошибка при сохранении");
        }
        if(!$response['success']){
            board::error($response['message']);
        }

        board::redirect("/fun/wheel/admin");
        board::success("Сохранено");
    }

    public function show($name)
    {
        validation::user_protection();

        $stories = sql::getRows(
          "SELECT `id`, `time`, `variables` FROM `logs_all` WHERE type='wheel_win' AND user_id = ? AND server_id = ? ORDER BY id DESC",
          [
            user::self()->getId(),
            user::self()->getServerId(),
          ]
        );
        foreach ($stories as &$story) {
            $time    = $story['time'];
            $story   = json_decode($story['variables']);
            $item_id = $story[0];
            $enchant = $story[1] ?? "";
            $count   = $story[3] ?? "";

            $info = client_icon::get_item_info($item_id);
            $info->setCount($count);
            $story = $info;
            $story->setEnchant($enchant);
            //Сделать строку time в DateTime
            $dateTime = DateTime::createFromFormat('Y-m-d H:i:s', $time);
            $story->setDate($dateTime);
        }
        $response = server::send(type::GET_WHEEL_ITEMS, [
          'name' => $name,
        ])->getResponse();
        if (isset($response['success']) and ! $response['success'] or $response['success'] == false) {
            redirect::location('/main');
        }
        foreach ($response['items'] as &$item) {
            $itemData             = client_icon::get_item_info($item['item_id']);
            $item['icon']         = $itemData->getIcon();
            $item['name']         = $itemData->getItemName();
            $item['add_name']     = $itemData->getAddName();
            $item['description']  = $itemData->getDescription();
            $item['item_type']    = $itemData->getType();
            $item['crystal_type'] = $itemData->getCrystalType();
        }

        tpl::addVar('stories', $stories);
        tpl::addVar('name', $name);
        tpl::addVar('cost', $response['cost']);
        tpl::addVar('items', json_encode($response['items']));
        tpl::displayPlugin("/wheel/tpl/wheel.html");
    }

    public function callback()
    {
        $name     = $_POST['name'] ?? board::error("Не удалось получить данные рулетки");
        $data     = [
          'name' => $name,
        ];
        $response = server::send(type::GAME_WHEEL, $data)->show()->getResponse();
        if ( ! $response) {
            board::error("Ошибка при получении данных");
        }
        if ($response['success']) {
            $itemData                          = client_icon::get_item_info($response['wheel']['item_id']);
            $response['wheel']['icon']         = $itemData->getIcon();
            $response['wheel']['name']         = $itemData->getItemName();
            $response['wheel']['add_name']     = $itemData->getAddName();
            $response['wheel']['description']  = $itemData->getDescription();
            $response['wheel']['item_type']    = $itemData->getType();
            $response['wheel']['crystal_type'] = $itemData->getCrystalType();

            $item = $response['wheel'];
            $cost = $response['cost'];

            //Если не удалось уменьшить деньги, то выводим ошибку
            if(!user::self()->donateDeduct($cost)){
                board::error("Произошла ошибка");
            }


            user::self()->addLog("wheel_win", '_LOG_User_Win_Wheel', [$item['item_id'], $item['enchant'], $item['name'], $item['count']]);
            board::alert([
                'success' => true,
                'wheel' => $response['wheel'],
            ]);
        }
    }

    //Список рулеток

    public function admin()
    {
        validation::user_protection();
        $arr      = [];
        $response = server::send(type::GET_WHEELS)->show()->getResponse();
        if ($response['success']) {
            foreach ($response['wheels'] as $wheel) {
                $arr[] = [
                  'name' => $wheel['name'],
                  'spin' => $wheel['spin'] ?? 0,
                  'cost' => $wheel['cost'] ?? 1,
                ];
            }
        }
        $names = array_map(function($item) {
            return $item['name'];
        }, $arr);

        //Удалить запись в server_cache из бд
        sql::run("DELETE FROM `server_cache` WHERE `type` = ? AND `server_id` = ?", [
          "__config_fun_wheel__",
          user::self()->getServerId(),
        ]);

        //Сохраним в server_cache для вывода в меню
        sql::run("INSERT INTO `server_cache` (`type`, `data`, `server_id`, `date_create`) VALUES (?, ?, ?, ?)", [
          "__config_fun_wheel__",
          json_encode($names, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
          user::self()->getServerId(),
          time::mysql(),
        ]);



        tpl::addVar('wheels', $arr);
        tpl::displayPlugin("/wheel/tpl/admin.html");
    }

    public function edit($name)
    {
        validation::user_protection("admin");
        $response = server::send(type::GET_WHEEL_ITEMS, [
          'name' => $name,
        ])->getResponse();
         //Проверка что

        if ( ! $response) {
            redirect::location('/fun/wheel/admin');
        }
        if ($response['success']) {
            if ( ! empty($response['items'])) {

                $items = $response['items'];
                $cost  = $response['cost'];
                $name = $response['name'];
                foreach ($items as &$item) {
                    $itemData             = client_icon::get_item_info($item['item_id']);
                    $item['icon']         = $itemData->getIcon();
                    $item['name']         = $itemData->getItemName();
                    $item['add_name']     = $itemData->getAddName();
                    $item['description']  = $itemData->getDescription();
                    $item['item_type']    = $itemData->getType();
                    $item['crystal_type'] = $itemData->getCrystalType();
                    $item['count']        = $item['count'] ?? 1;
                    $item['enchant']      = $item['enchant'] ?? 0;
                    $item['probability']  = $item['probability'] ?? 0.00;
                    $item['count_min']    = $item['count_min'] ?? null;
                    $item['count_max']    = $item['count_max'] ?? null;
                    $item['count_type']   = $item['count_type'] ?? null;
                }
                tpl::addVar('object_id', (int)$response['object_id']);
                tpl::addVar('name', $name);
                tpl::addVar('cost', (int)$cost);
                tpl::addVar('wheelsItems', $items ?? []);
            }
        }
        tpl::addVar('title', 'Добавление рулетки');
        tpl::displayPlugin('/wheel/tpl/edit.html');
    }

    public function create()
    {
        validation::user_protection("admin");
        //
        //        $__config_fun_wheel__ = sql::getRow("SELECT * FROM `settings` WHERE serverId = ? AND `key` = '__config_fun_wheel__'", [
        //          user::self()->getServerId(),
        //        ]);
        //        if ($__config_fun_wheel__) {
        //            tpl::addVar('items', json_decode($__config_fun_wheel__['setting'], true));
        //        }

        tpl::addVar('title', 'Добавление рулетки');
        tpl::displayPlugin('/wheel/tpl/create.html');
    }

    public function editName()
    {
        $old_name   = $_POST['old_name'] ?? '';
        $new_name   = $_POST['new_name'] ?? '';
        $wheel_cost = $_POST['wheel_cost'] ?? 1;
        if ($old_name == $new_name) {
            board::error("Новое название не может быть равным старому");
        }
        if (strlen($new_name) > 20) {
            board::error("Длина нового названия не может быть больше 20 символов");
        }
        if (strlen($new_name) < 3) {
            board::error("Длина нового названия должна быть больше 3 символов");
        }
        //Цена прокрутки, может быть float 0.01, но больше нуля
        if ($wheel_cost < 0) {
            board::error("Цена прокрутки должна быть больше 0");
        }

        //Удаление старых данных
        $select = sql::getRows("SELECT * FROM `server_data` WHERE `key` = ? AND `server_id` = ?", [
          "__config_fun_wheel__",
          user::self()->getServerId(),
        ]);
        foreach ($select as $data) {
            $val = json_decode($data['val'], true);
            if ($val['name'] == $old_name) {
                sql::run("DELETE FROM `server_data` WHERE `id` = ?", [
                  $data['id'],
                ]);
            }
        }

        $data = [
          'name' => $new_name,
          'cost' => $wheel_cost,
        ];

        $sql = "INSERT INTO `server_data` (`key`, `val`, `server_id`, `date`) VALUES (?, ?, ?, ?)";
        sql::run($sql, [
          "__config_fun_wheel__",
          json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
          user::self()->getServerId(),
          time::mysql(),
        ]);

        $data = [
          'old_name' => $old_name,
          'new_name' => $new_name,
        ];

        server::send(type::GAME_WHEEL_EDIT_NAME, $data);

        board::reload();
        board::success("Сохранено");
    }

    public function remove()
    {
        $name     = $_POST['name'] ?? board::error("Не удалось получить данные рулетки");
        $data     = [
          'name' => $name,
        ];
        $response = server::send(type::GAME_WHEEL_REMOVE, $data)->show()->getResponse();
        if (isset($response['success'])) {
            if ($response['success']) {
                $rows = sql::getRows("DELETE FROM `server_data` WHERE `key` = ? AND `server_id` = ?", [
                  "__config_fun_wheel__",
                  user::self()->getServerId(),
                ]);
                foreach ($rows as $data) {
                    $val = json_decode($data['val'], true);
                    if ($val['name'] == $name) {
                        sql::run("DELETE FROM `server_data` WHERE `id` = ?", [
                          $data['id'],
                        ]);
                    }
                }

                board::success("Удаление");
            } else {
                board::error($response['error']);
            }
        }
    }

}