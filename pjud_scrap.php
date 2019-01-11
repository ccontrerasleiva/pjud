<?php

require_once 'Conexion.php';
require __DIR__ . '/vendor/autoload.php';
require 'CsvImporter.php';
require 'PHPExcel.php';
header('Content-Type: text/html; charset=UTF-8');

use Zend\Dom\Query;
use Zend\Http\Client;

class Scrap
{


    function insertaBusquedaRut($demandados)
    {
        $connect = new Conexion();
        $connect->insertaBusquedaRut($demandados);


    }

    function insertaBeco($beco, $solicitud)
    {
        $connect = new Conexion();
        $connect->insertaBeco($beco, $solicitud);
    }

    /**
     * @param $resultados
     * @param $rut
     */
    function  busquedaRut($resultados, $rut)
    {
        $rols = array();
        $indice = 0;
        foreach ($resultados as $resultado) {
            $contador = 0;
            foreach ($resultado->getElementsByTagName('td') as $td) {
                if ($contador == 1) {
                    $rols[$indice]['rut'] = $rut;
                    $rols[$indice]['rol'] = str_replace(' ', '', $td->nodeValue);
                }
                if ($contador == 5) {
                    $rols[$indice]['tribunal'] = $td->nodeValue;
                }
                $contador++;
            }
            $indice++;
        }

        $connect = new Conexion();
        $connect->insertaRol($rols);
        $connect->actualizaCodigoTribunal();


    }

    function limpiaBase()
    {
        $connect = new Conexion();
        $connect->limpiaBase();
    }

    function insertaBusquedaRol($rols)
    {
        $connect = new Conexion();
        $connect->insertaRol($rols);
        $connect->actualizaCodigoTribunal();

    }

    function insertaBusquedaNombre($busqueda)
    {
        $connect = new Conexion();
        $connect->insertaBusquedaNombre($busqueda);
    }

    function buquedaPorNombre($nombres)
    {
        $client = new Client('http://civil.poderjudicial.cl', array(
            'maxredirects' => 100,
            'timeout' => 600,
            'keepalive' => true
        ));

        /*
         * Obtenemos las cabeceras y seteamos las cookies.
         */

        $headers = $client->getRequest()->getHeaders();
        $cookies = new Zend\Http\Cookies($headers);

        $client->setMethod('GET');
        $response = $client->send();

        $client->setUri('http://civil.poderjudicial.cl/CIVILPORWEB/AtPublicoViewAccion.do?tipoMenuATP=1');

        $cookies->addCookiesFromResponse($response, $client->getUri());
        $response = $client->send();

        foreach ($nombres as $demandado) {
            echo "<pre>Se estan buscando las causas para." . $demandado['nombre'] . " " . $demandado['apPaterno'] . " " . $demandado['apMaterno'] . ", RUT: " . $demandado['rut'] . "</pre>";

            $rut = $demandado['rut'];


            $client->setUri('http://civil.poderjudicial.cl/CIVILPORWEB/AtPublicoDAction.do');
            $cookies->addCookiesFromResponse($response, $client->getUri());

            $client->setParameterPost(array(
                'TIP_Consulta' => '3',
                'TIP_Lengueta' => 'tdCuatro',
                'SeleccionL' => '0',
                'TIP_Causa' => '',
                'ROL_Causa' => '',
                'ERA_Causa' => '',
                'RUC_Era' => '',
                'RUC_Tribunal' => '3',
                'RUC_Numero' => '',
                'RUC_Dv' => '',
                'FEC_Desde' => '20/08/2015',
                'FEC_Hasta' => '20/08/2015',
                'SEL_Litigantes' => '0',
                'RUT_Consulta' => '',
                'RUT_DvConsulta' => '',
                'NOM_Consulta' => $demandado['nombre'],
                'APE_Paterno' => $demandado['apPaterno'],
                'APE_Materno' => $demandado['apMaterno'],
                'COD_Tribunal' => '0',
                'irAccionAtPublico' => 'Consulta'
            ));

            $response = $client->setMethod('POST')->send();
            if ($response->isSuccess()) {

                $data = $response->getContent();
                $dom = new Query($data);

                $results = $dom->execute('#contentCellsAddTabla tr');
                $informacionCausas = $this->getCausas($results);
                echo '<pre>Se encontraron: ' . count($informacionCausas) . ' causas</pre>';
                $postCausas = $this->setPostCausas($informacionCausas);

                $client->setUri("http://civil.poderjudicial.cl/CIVILPORWEB/ConsultaDetalleAtPublicoAccion.do?");

                $cookies->addCookiesFromResponse($response, $client->getUri());

                foreach ($postCausas as $post) {
                    $client->setParameterPost(array(
                        "TIP_Consulta" => $post['TIP_Consulta'],
                        "TIP_Cuaderno" => $post['TIP_Cuaderno'],
                        "CRR_IdCuaderno" => $post['CRR_IdCuaderno'],
                        "ROL_Causa" => $post['ROL_Causa'],
                        "TIP_Causa" => $post['TIP_Causa'],
                        "ERA_Causa" => $post['ERA_Causa'],
                        "COD_Tribunal" => $post['COD_Tribunal'],
                        "TIP_Informe" => $post['TIP_Informe'] . "&"
                    ));

                    $response = $client->setMethod('POST')->send();

                    if ($response->isOk()) {


                        $data = $response->getContent();


                        $dom = new Query($data);

                        $detalleCausa = $dom->execute('tr');
                        $rol = $post['TIP_Causa'] . '-' . $post['ROL_Causa'] . '-' . $post['ERA_Causa'];
                        $arr_detalles = $this->infoCausas($detalleCausa, $rol, $rut);
                        die();
                        $cuadernos = $dom->execute("#TablaCuadernos .comboBox option");
                        $contador = 0;
                        foreach ($cuadernos as $cuaderno) {
                            $arr_cuadernos[$contador]['nombre'] = $cuaderno->textContent;
                            $arr_cuadernos[$contador]['id'] = $cuaderno->attributes->getNamedItem('value')->textContent;
                            $contador++;
                        }

                        $litigantes = $dom->execute("#Litigantes table tr");

                        $arr_litigantes = $this->getLitigantes($litigantes, $rol, $demandado['rut'], $arr_cuadernos[0]['nombre']);

                        $tribunal = $arr_detalles['tribunal'];


                        $historias = $dom->execute("#Historia tr");
                        $arr_historias = $this->getHistorias($historias, $rol, $rut, $tribunal, $arr_cuadernos[0]['nombre']);


                        foreach ($arr_litigantes as $ar) {
                            if (strpos($ar['rut'], $rut) !== false && strcmp($arr_detalles['estado_proceso'], "Exception Stack Trace") != 0) {
                                echo "<pre> Entr igual: " . $arr_detalles['estado_proceso'] . "</pre>";
                                $connect = new Conexion();
                                $connect->insertCausa($arr_detalles, $rut);
                                $connect->insertCausaHistoria($arr_historias);

                            }
                        }


                        if (count($cuadernos) > 1) {
                            $documento = $dom->getDocument();

                            preg_match_all("/(TIP_Cuaderno.value.*')/", $documento, $coincidencias);
                            $arr_tips_cuadernos = $coincidencias[0];
                            preg_match_all("/(CRR_IdCuaderno.value.*)/", $documento, $crr_id);
                            $arr_crr_cuadernos = $crr_id[0];


                            $contador = 0;
                            for ($i = 1; $i < count($cuadernos); $i++) {
                                $postCuaderno[$contador]['tip'] = str_replace("TIP_Cuaderno.value   = '", "", $arr_tips_cuadernos[$i]);
                                $postCuaderno[$contador]['tip'] = str_replace("'", "", $postCuaderno[$contador]['tip']);
                                $postCuaderno[$contador]['crr'] = str_replace("CRR_IdCuaderno.value = '", "", $arr_crr_cuadernos[$i]);
                                $postCuaderno[$contador]['crr'] = str_replace("';", "", $postCuaderno[$contador]['crr']);
                                $postCuaderno[$contador]['crr'] = trim(rtrim($postCuaderno[$contador]['crr'], " "));
                                $postCuaderno[$contador]['gls'] = $arr_cuadernos[$i]['nombre'];
                                $postCuaderno[$contador]['tip_causa'] = $post['TIP_Causa'];
                                $postCuaderno[$contador]['rol_causa'] = $post['ROL_Causa'];
                                $postCuaderno[$contador]['era_causa'] = $post['ERA_Causa'];
                                $postCuaderno[$contador]['cod_tribunal'] = $post['COD_Tribunal'];

                                $contador++;
                            }


                            foreach ($postCuaderno as $cuaderno) {

                                $post_parameters = array(
                                    'TIP_Causa' => $cuaderno['tip_causa'],
                                    'ROL_Causa' => $cuaderno['rol_causa'],
                                    'ERA_Causa' => $cuaderno['era_causa'],
                                    'COD_Tribunal' => $cuaderno['cod_tribunal'],
                                    'TIP_Cuaderno' => $cuaderno['tip'],
                                    'GLS_Cuaderno' => $cuaderno['gls'],
                                    'CRR_IdCuaderno' => $cuaderno['crr'],
                                    'TIP_Informe' => '1',
                                    'FLG_Caratula' => '0',
                                    'TIP_Cargo' => '2',
                                    'COD_Corte' => '98',
                                    'FLG_ImpresionTribunal' => '1',
                                    'CRR_Cuaderno' => $cuaderno['crr'],
                                    'irAccionAtPublico' => 'Ir a Cuaderno',
                                    'FLG_Vuelta' => 'null'
                                );

                                $client->setUri("http://civil.poderjudicial.cl/CIVILPORWEB/AtPublicoDAction.do");
                                $cookies->addCookiesFromResponse($response, $client->getUri());

                                $client->setParameterPost($post_parameters);

                                $response = $client->setMethod('POST')->send();

                                $data = $response->getContent();
                                $dom = new Query($data);

                                $cuaderno = $cuaderno['gls'];
                                $tribunal = $arr_detalles['tribunal'];

                                $litigantes = $dom->execute("#Litigantes table tr");
                                $arr_litigantes = $this->getLitigantes($litigantes, $rol, $demandado['rut'], $arr_cuadernos[0]['nombre']);

                                $historias = $dom->execute("#Historia tr");
                                $arr_historias = $this->getHistorias($historias, $rol, $rut, $tribunal, $cuaderno);

                                foreach ($arr_litigantes as $ar) {
                                    if (strpos($ar['rut'], $rut) !== false) {
                                        $connect = new Conexion();
                                        $connect->insertCausaHistoria($arr_historias);

                                    }
                                }

                            }
                        }

                    } else {
                        echo "No se ha encontrado la causa";
                    }


                }
            }
        }
    }

    function exportBEco($solicitud)
    {
        $connect = new Conexion();
        $exportBeco = $connect->exportBeco($solicitud);

        return $exportBeco;
    }

    function buscaCausas($log, $tabla)
    {
        $client2 = new Client('http://civil.poderjudicial.cl', array(
            'maxredirects' => 100,
            'timeout' => 600,
            'keepalive' => true
        ));

        $headers = $client2->getRequest()->getHeaders();
        $cookies = new Zend\Http\Cookies($headers);

        $client2->setMethod('GET');
        $response = $client2->send();

        $client2->setUri('http://civil.poderjudicial.cl/CIVILPORWEB/AtPublicoViewAccion.do?tipoMenuATP=1');

        $cookies->addCookiesFromResponse($response, $client2->getUri());
        $response = $client2->send();
        if ($response->isSuccess()) {


            $post2 = $this->setPostBPR($log, $tabla);


            //$contador = 0;
            echo '<pre>POSTS: ' . count($post2) . '</pre>';
            foreach ($post2 as $busqueda2) {

                $rut_dmo = $busqueda2[0];
                $rol = explode('-', $busqueda2[1]);
                $tip_causa = $rol[0];
                $rol_causa = $rol[1];
                $era_causa = $rol[2];
                $cod_tribunal = $busqueda2[2];

                $client2->setUri('http://civil.poderjudicial.cl/CIVILPORWEB/AtPublicoDAction.do');
                $cookies->addCookiesFromResponse($response, $client2->getUri());

                $client2->setParameterPost(array(
                    'TIP_Consulta' => '1',
                    'TIP_Lengueta' => 'tdUno',
                    'SeleccionL' => '0',
                    'TIP_Causa' => $tip_causa,
                    'ROL_Causa' => $rol_causa,
                    'ERA_Causa' => $era_causa,
                    'RUC_Era' => '',
                    'RUC_Tribunal' => '3',
                    'RUC_Numero' => '',
                    'RUC_Dv' => '',
                    'FEC_Desde' => '19/10/2015',
                    'FEC_Hasta' => '19/10/2015',
                    'SEL_Litigantes' => '0',
                    'RUT_Consulta' => '',
                    'RUT_DvConsulta' => '',
                    'NOM_Consulta' => '',
                    'APE_Paterno' => '',
                    'APE_Materno' => '',
                    'COD_Tribunal' => $cod_tribunal,
                    'irAccionAtPublico' => 'Consulta'
                ));

                $response = $client2->setMethod('POST')->send();

                $data = $response->getContent();

                $dom = new Query($data);

                $results = $dom->execute('#contentCellsAddTabla tr');
                $informacionCausas = $this->getCausas($results);
                $postCausas = $this->setPostCausas($informacionCausas);

                $client2->setUri("http://civil.poderjudicial.cl/CIVILPORWEB/ConsultaDetalleAtPublicoAccion.do?");

                $cookies->addCookiesFromResponse($response, $client2->getUri());

                foreach ($postCausas as $post) {

                    $client2->setParameterPost(array(
                        "TIP_Consulta" => $post['TIP_Consulta'],
                        "TIP_Cuaderno" => $post['TIP_Cuaderno'],
                        "CRR_IdCuaderno" => $post['CRR_IdCuaderno'],
                        "ROL_Causa" => $post['ROL_Causa'],
                        "TIP_Causa" => $post['TIP_Causa'],
                        "ERA_Causa" => $post['ERA_Causa'],
                        "COD_Tribunal" => $post['COD_Tribunal'],
                        "TIP_Informe" => $post['TIP_Informe'] . "&"
                    ));

                    $response = $client2->setMethod('POST')->send();

                    $data = $response->getContent();

                    $dom = new Query($data);
                    $cuadernos = $dom->execute("#TablaCuadernos .comboBox option");
                    $contador = 0;
                    foreach ($cuadernos as $cuaderno) {
                        $arr_cuadernos[$contador]['nombre'] = $cuaderno->textContent;
                        $arr_cuadernos[$contador]['id'] = $cuaderno->attributes->getNamedItem('value')->textContent;
                        $contador++;
                    }
                    $detalleCausa = $dom->execute('tr');
                    $rol = $post['TIP_Causa'] . '-' . $post['ROL_Causa'] . '-' . $post['ERA_Causa'];
                    $arr_detalles = $this->infoCausas($detalleCausa, $busqueda2[1], $rut_dmo);

                    $cuaderno = $arr_cuadernos[0]['nombre'];

                    $litigantes = $dom->execute("#Litigantes table tr");
                    $arr_litigantes = $this->getLitigantes($litigantes, $busqueda2[1], $rut_dmo, $cuaderno);


                    $tribunal = $arr_detalles['tribunal'];

                    $historias = $dom->execute("#Historia tr");
                    $arr_historias = $this->getHistorias($historias, $rol, $rut_dmo, $tribunal, $cuaderno);
                    if (count($cuadernos) > 1) {
                        $documento = $dom->getDocument();

                        preg_match_all("/(TIP_Cuaderno.value.*')/", $documento, $coincidencias);
                        $arr_tips_cuadernos = $coincidencias[0];
                        preg_match_all("/(CRR_IdCuaderno.value.*)/", $documento, $crr_id);
                        $arr_crr_cuadernos = $crr_id[0];


                        $contador = 0;
                        for ($i = 1; $i < count($cuadernos); $i++) {
                            $postCuaderno[$contador]['tip'] = str_replace("TIP_Cuaderno.value   = '", "", $arr_tips_cuadernos[$i]);
                            $postCuaderno[$contador]['tip'] = str_replace("'", "", $postCuaderno[$contador]['tip']);
                            $postCuaderno[$contador]['crr'] = str_replace("CRR_IdCuaderno.value = '", "", $arr_crr_cuadernos[$i]);
                            $postCuaderno[$contador]['crr'] = str_replace("';", "", $postCuaderno[$contador]['crr']);
                            $postCuaderno[$contador]['crr'] = trim(rtrim($postCuaderno[$contador]['crr'], " "));
                            $postCuaderno[$contador]['gls'] = $arr_cuadernos[$i]['nombre'];
                            $postCuaderno[$contador]['tip_causa'] = $post['TIP_Causa'];
                            $postCuaderno[$contador]['rol_causa'] = $post['ROL_Causa'];
                            $postCuaderno[$contador]['era_causa'] = $post['ERA_Causa'];
                            $postCuaderno[$contador]['cod_tribunal'] = $post['COD_Tribunal'];

                            $contador++;
                        }


                        foreach ($postCuaderno as $cuaderno) {
                            echo '<pre>';
                            print_r($cuaderno);
                            echo '</pre>';

                            $post_parameters = array(
                                'TIP_Causa' => $cuaderno['tip_causa'],
                                'ROL_Causa' => $cuaderno['rol_causa'],
                                'ERA_Causa' => $cuaderno['era_causa'],
                                'COD_Tribunal' => $cuaderno['cod_tribunal'],
                                'TIP_Cuaderno' => $cuaderno['tip'],
                                'GLS_Cuaderno' => $cuaderno['gls'],
                                'CRR_IdCuaderno' => $cuaderno['crr'],
                                'TIP_Informe' => '1',
                                'FLG_Caratula' => '0',
                                'TIP_Cargo' => '2',
                                'COD_Corte' => '98',
                                'FLG_ImpresionTribunal' => '1',
                                'CRR_Cuaderno' => $cuaderno['crr'],
                                'irAccionAtPublico' => 'Ir a Cuaderno',
                                'FLG_Vuelta' => 'null'
                            );

                            $client2->setUri("http://civil.poderjudicial.cl/CIVILPORWEB/AtPublicoDAction.do");
                            $cookies->addCookiesFromResponse($response, $client2->getUri());

                            $client2->setParameterPost($post_parameters);

                            $response = $client2->setMethod('POST')->send();

                            $data = $response->getContent();
                            $dom = new Query($data);

                            $cuaderno = $cuaderno['gls'];
                            $tribunal = $arr_detalles['tribunal'];

                            $historias = $dom->execute("#Historia tr");
                            $arr_historias = $this->getHistorias($historias, $rol, $rut_dmo, $tribunal, $cuaderno);
                        }
                    }


                }
            }
        } //$contador++;
        else {
            echo 'ha ocurrido un problema, por favor dirigase a la pestaña de log y restaure la busuqeda desde el punto en que se detuvo el proceso';
        }

    }


    function getCausas($results)
    {
        $informacionCausa = array();

        foreach ($results as $result) {
            foreach ($result->getElementsByTagName('a') as $a) {
                $links[] = trim($a->getAttribute('href'));
            }
        }
        foreach ($results as $result) {

            $c_columnas = 0;
            foreach ($result->getElementsByTagName('td') as $td) {
                if ($c_columnas == 0) {

                    $link = trim($td->getElementsByTagName('a')->item(0)->getAttribute('href'));
                    $rol = trim($td->nodeValue);
                }
                if ($c_columnas == 1) {
                    $fecha = trim($td->nodeValue);
                }
                if ($c_columnas == 2) {
                    $caratulado = trim($td->nodeValue);
                }
                if ($c_columnas == 3) {
                    $juzgado = trim($td->nodeValue);
                }

                $c_columnas++;

            }
            $informacionCausa[] = array(
                "Link" => $link,
                "ROL" => $rol,
                "Fecha" => $fecha,
                "Caratulado" => $caratulado,
                "Juzgado" => $juzgado
            );

        }

        return $informacionCausa;
    }


    /**
     * @param $informacionCausas
     * @return array
     */
    function setPostCausas($informacionCausas)
    {

        $posts = array();
        $links = array();
        $infoPost = array();

        for ($i = 0; $i < count($informacionCausas); $i++) {
            $links[$i] = $informacionCausas[$i]['Link'];
        }

        for ($i = 0; $i < count($links); $i++) {
            $links[$i] = str_replace('/CIVILPORWEB/ConsultaDetalleAtPublicoAccion.do?', '', $links[$i]);
        }

        for ($i = 0; $i < count($links); $i++) {
            $infoPost[$i][] = explode('&', $links[$i]);
        }

        foreach ($infoPost as $post) {
            foreach ($post as $data) {
                $posts[] = array(
                    "TIP_Consulta" => substr($data[0], 13),
                    "TIP_Cuaderno" => substr($data[1], 13),
                    "CRR_IdCuaderno" => substr($data[2], 15),
                    "ROL_Causa" => substr($data[3], 10),
                    "TIP_Causa" => substr($data[4], 10),
                    "ERA_Causa" => substr($data[5], 10),
                    "CRR_IdCausa" => substr($data[6], 12),
                    "COD_Tribunal" => substr($data[7], 13),
                    "TIP_Informe" => substr($data[8], 12) . "&"
                );
            }
        }

        return $posts;
    }

    /**
     * @param $infoCausa
     * @return array
     */
    function infoCausas($infoCausa, $rol_bueno, $rut)
    {
        $arr_infoCausa = array();

        foreach ($infoCausa as $info) {
            foreach ($info->getElementsByTagName('td') as $td) {
                $registros[] = $td;
            }
        }

        $rol = $rol_bueno;
        $cuaderno_ppal = trim($registros[3]->textContent);
        $fec_ingreso = trim($registros[4]->textContent);
        $fec_ingreso = str_replace(" ", "", $fec_ingreso);
        $fec_ingreso = str_replace("F.Ing:", "", $fec_ingreso);
        $est_admin = $registros[5]->textContent;
        $est_admin = trim(str_replace("Est.Adm.:", "", $est_admin));
        $proc = str_replace("Proc.:", "", $registros[6]->textContent);
        $ubicacion = str_replace("Ubicación:", "", $registros[7]->textContent);
        $etapa = trim(str_replace("Etapa:", "", $registros[8]->textContent));
        $estado_proceso = trim(str_replace("Estado Proc.:", "", $registros[9]->textContent));
        $tribunal = trim(str_replace("Tribunal :", "", $registros[10]->textContent));
        $tribunal = str_replace("º", "", $tribunal);

        $arr_infoCausa = array(
            "rol" => $rol,
            "cuaderno_ppal" => $cuaderno_ppal,
            "fec_ingreso" => $fec_ingreso,
            "est_admin" => $est_admin,
            "proc" => $proc,
            "ubicacion" => $ubicacion,
            "etapa" => $etapa,
            "estado_proceso" => $estado_proceso,
            "tribunal" => $tribunal);

        $connect = new Conexion();
        $connect->insertCausa($arr_infoCausa, $rut);

        return $arr_infoCausa;
    }

    /*function getIdCuadernoos($cuadernos)
    {
        $idCuadernos = "";

        foreach ($cuadernos as $cuaderno) {
            foreach ($cuaderno->getElementsByTagName('option') as $optionTag) {
                foreach ($optionTag->attributes as $attributes) {
                    $attributes->textContent;
                    if ($attributes->textContent != "selected") {
                        $idCuadernos[] = $attributes->textContent;
                    }
                }

            }
        }

        return $idCuadernos;
    }*/

    /**
     * @param $cuadernos
     */
    /*function getNombreCuadernos($cuadernos)
    {

        $nombreCuaderno = "";

        foreach ($cuadernos as $cuaderno) {
            foreach ($cuaderno->getElementsByTagName('option') as $optionTag) {
                $nombreCuaderno = $optionTag->textContent;
            }
        }

        return $nombreCuaderno;
    }*/

    function setPostCuadernos($posts)
    {
        $contador = 0;
        foreach ($posts as $post) {
            $parameters[$contador]['TIP_Causa'] = $post['tip_causa'];
            $parameters[$contador]['ROL_Causa'] = $post['rol_causa'];
            $parameters[$contador]['ERA_Causa'] = $post['era_causa'];
            $parameters[$contador]['COD_Tribunal'] = $post['cod_tribunal'];
            $parameters[$contador]['TIP_Cuaderno'] = $post['tip'];
            $parameters[$contador]['GLS_Cuaderno'] = '';
            $parameters[$contador]['CRR_IdCuaderno'] = $post['crr'];
            $parameters[$contador]['TIP_Informe'] = "1";
            $parameters[$contador]['FLG_Caratula'] = "0";
            $parameters[$contador]['TIP_Cargo'] = "2";
            $parameters[$contador]['COD_Corte'] = "98";
            $parameters[$contador]['FLG_ImpresionTribunal'] = "1";
            $parameters[$contador]['CRR_Cuaderno'] = $post['crr'];
            $parameters[$contador]['irAccionAtPublico'] = "Ir a Cuaderno";
            $parameters[$contador]['FLG_Vuelta'] = "null";
            $contador++;
        }


        return $parameters;
    }


    function getHistorias($historias, $rol, $rut, $tribunal, $nombreCuaderno)
    {

        $cabeceras = array('Folio', 'Doc.', 'Etapa', 'Trámite', 'Desc. Trámite', 'Fec.Tram', 'Foja', 'Participante', 'Rut', 'Persona', 'Nombre o Razón Social');

        foreach ($historias as $historia) {

            $columnas = 0;
            foreach ($historia->getElementsByTagName('td') as $td) {

                if (!in_array($td->textContent, $cabeceras)) {
                    if ($columnas == 0) {
                        $folios[] = trim($td->textContent);
                    }

                    if ($columnas == 1) {

                        $img = $td->getElementsByTagName('img')->item(0);
                        if ($img === null) {
                            $documentos[] = "No se ha encontrado documento asociado";
                        } else {
                            $documentos[] = trim($img->getAttribute('onclick'));
                        }

                    }

                    if ($columnas == 2) {
                        $etapas[] = trim($td->textContent);

                    }

                    if ($columnas == 3) {
                        $tramites[] = trim($td->textContent);
                    }

                    if ($columnas == 4) {
                        $desTramite[] = trim($td->textContent);
                    }

                    if ($columnas == 5) {
                        $fecTramite[] = trim($td->textContent);
                    }

                    if ($columnas == 6) {
                        $fojas[] = trim($td->textContent);
                    }
                    $columnas++;


                }
            }

        }


        for ($i = 0; $i < count($documentos); $i++) {
            $documentos[$i] = str_replace("ShowPDFCabecera('/", "", $documentos[$i]);
            $documentos[$i] = str_replace("ShowWord('", "", $documentos[$i]);
            $documentos[$i] = str_replace("ShowImage('/", "", $documentos[$i]);
            $documentos[$i] = str_replace("')", "", $documentos[$i]);
        }


        for ($i = 0; $i < count($folios); $i++) {

            $causas[$i]['rut'] = $rut;
            $causas[$i]['rol'] = $rol;
            $causas[$i]['folio'] = $folios[$i];
            if ($documentos[$i] === "alert('No existe documento asociado..") {
                $causas[$i]['documento'] = "No existe documento asociado";
            } else {
                $causas[$i]['documento'] = "http://civil.poderjudicial.cl/" . $documentos[$i];

            }
            $causas[$i]['etapa'] = $etapas[$i];
            $causas[$i]['tramite'] = $tramites[$i];
            $causas[$i]['descTramite'] = $desTramite[$i];
            $causas[$i]['fecTramite'] = $fecTramite[$i];
            $causas[$i]['foja'] = $fojas[$i];
            $causas[$i]['tribunal'] = $tribunal;
            $causas[$i]['cuaderno'] = $nombreCuaderno;
        }

        $conexion = new Conexion();
        $conexion->insertCausaHistoria($causas);
        return $causas;

    }

    /**
     * @param $litigantes
     * @return mixed
     */
    function getLitigantes($litigantes, $rol, $rut, $cuaderno)
    {

        $cabeceras = array('Folio', 'Doc.', 'Etapa', 'Trámite', 'Desc. Trámite', 'Fec.Tram', 'Foja', 'Participante', 'Rut', 'Persona', 'Nombre o Razón Social');

        foreach ($litigantes as $litigante) {
            $columnas = 0;
            foreach ($litigante->getElementsByTagName('td') as $td) {

                if (!in_array($td->textContent, $cabeceras)) {

                    if ($columnas == 0) {
                        $participantes[] = $td->textContent;
                    }
                    if ($columnas == 1) {
                        $rutsLitigantes[] = $td->textContent;
                    }
                    if ($columnas == 2) {
                        $personas[] = $td->textContent;
                    }
                    if ($columnas == 3) {
                        $nombres[] = $td->textContent;
                    }
                }
                $columnas++;
            }


        }


        for ($i = 0; $i < count($participantes); $i++) {
            $arr_litigantes[$i]['rol'] = $rol;
            $arr_litigantes[$i]['participante'] = $participantes[$i];
            $arr_litigantes[$i]['rut'] = str_replace("-", "", $rutsLitigantes[$i]);
            $arr_litigantes[$i]['nombre'] = trim($nombres[$i]);
            $arr_litigantes[$i]['tipo_persona'] = $personas[$i];
            $arr_litigantes[$i]['cuaderno'] = $cuaderno;

        }


        return $arr_litigantes;
    }
}





