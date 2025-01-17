<?php
/**
 * This file is part of StockAvanzado plugin for FacturaScripts
 * Copyright (C) 2020-2022 Carlos Garcia Gomez <carlos@facturascripts.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace FacturaScripts\Plugins\StockAvanzado\Lib;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Base\ToolBox;
use FacturaScripts\Dinamic\Model\Almacen;
use FacturaScripts\Dinamic\Model\Stock;

/**
 * Description of StockRebuild
 *
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 */
class StockRebuild
{
    private static $database;

    public static function rebuild(): bool
    {
        self::dataBase()->beginTransaction();
        static::clear();

        $warehouse = new Almacen();
        foreach ($warehouse->all([], [], 0, 0) as $war) {
            foreach (static::calculateStockData($war->codalmacen) as $data) {
                $stock = new Stock();
                $where = [
                    new DataBaseWhere('codalmacen', $data['codalmacen']),
                    new DataBaseWhere('referencia', $data['referencia'])
                ];
                if ($stock->loadFromCode('', $where)) {
                    // el stock ya existe
                    $stock->loadFromData($data);
                    $stock->save();
                    continue;
                }

                // creamos y guardamos el stock
                $newStock = new Stock($data);
                $newStock->save();
            }
        }

        ToolBox::i18nLog()->notice('rebuilt-stock');
        ToolBox::i18nLog('audit')->warning('rebuilt-stock');

        self::dataBase()->commit();
        return true;
    }

    protected static function clear(): bool
    {
        if (self::dataBase()->tableExists('stocks')) {
            $sql = "UPDATE stocks SET cantidad = '0', disponible = '0', pterecibir = '0', reservada = '0';";
            return self::dataBase()->exec($sql);
        }

        return true;
    }

    protected static function calculateStockData(string $codalmacen): array
    {
        if (false === self::dataBase()->tableExists('stocks_movimientos')) {
            return [];
        }

        $stockData = [];
        $sql = "SELECT referencia, SUM(cantidad) as sum FROM stocks_movimientos"
            . " WHERE codalmacen = " . self::dataBase()->var2str($codalmacen)
            . " GROUP BY 1";
        foreach (self::dataBase()->select($sql) as $row) {
            $ref = trim($row['referencia']);
            $stockData[$ref] = [
                'cantidad' => (float)$row['sum'],
                'codalmacen' => $codalmacen,
                'pterecibir' => 0,
                'referencia' => $ref,
                'reservada' => 0
            ];
        }

        static::setPterecibir($stockData, $codalmacen);
        static::setReservada($stockData, $codalmacen);
        return $stockData;
    }

    protected static function dataBase(): DataBase
    {
        if (!isset(self::$database)) {
            self::$database = new DataBase();
        }

        return self::$database;
    }

    protected static function setPterecibir(array &$stockData, string $codalmacen)
    {
        if (false === self::dataBase()->tableExists('lineaspedidosprov')) {
            return;
        }

        $sql = "SELECT l.referencia,"
            .        " SUM(CASE WHEN l.cantidad > l.servido THEN l.cantidad - l.servido ELSE 0 END) as pte"
            . " FROM lineaspedidosprov l"
            . " LEFT JOIN pedidosprov p ON l.idpedido = p.idpedido"
            . " WHERE l.referencia IS NOT NULL"
            . " AND l.actualizastock = '2'"
            . " AND p.codalmacen = " . self::dataBase()->var2str($codalmacen)
            . " GROUP BY 1;";
        foreach (self::dataBase()->select($sql) as $row) {
            $ref = trim($row['referencia']);
            if (!isset($stockData[$ref])) {
                $stockData[$ref] = [
                    'cantidad' => 0,
                    'codalmacen' => $codalmacen,
                    'pterecibir' => 0,
                    'referencia' => $ref,
                    'reservada' => 0
                ];
            }

            $stockData[$ref]['pterecibir'] = (float)$row['pte'];
        }
    }

    protected static function setReservada(array &$stockData, string $codalmacen)
    {
        if (false === self::dataBase()->tableExists('lineaspedidoscli')) {
            return;
        }

        $sql = "SELECT l.referencia,"
            .        " SUM(CASE WHEN l.cantidad > l.servido THEN l.cantidad - l.servido ELSE 0 END) as reservada"
            . " FROM lineaspedidoscli l"
            . " LEFT JOIN pedidoscli p ON l.idpedido = p.idpedido"
            . " WHERE l.referencia IS NOT NULL"
            . " AND l.actualizastock = '-2'"
            . " AND p.codalmacen = " . self::dataBase()->var2str($codalmacen)
            . " GROUP BY 1;";
        foreach (self::dataBase()->select($sql) as $row) {
            $ref = trim($row['referencia']);
            if (!isset($stockData[$ref])) {
                $stockData[$ref] = [
                    'cantidad' => 0,
                    'codalmacen' => $codalmacen,
                    'pterecibir' => 0,
                    'referencia' => $ref,
                    'reservada' => 0
                ];
            }

            $stockData[$ref]['reservada'] = (float)$row['reservada'];
        }
    }
}
