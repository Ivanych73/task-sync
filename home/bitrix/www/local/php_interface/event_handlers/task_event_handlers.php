<?php

AddEventHandler("tasks", "OnTaskAdd", ["TaskEventHandlers", "SendB24TaskTo1c"]);
AddEventHandler("tasks", "OnBeforeTaskAdd", ["TaskEventHandlers", "ValidateB24Task"]);

class TaskEventHandlers
{
	private const B_24_TAG = "<tag_title>";
	private const USER_FIELD_SUMM_TITLE = "<user_field_code>";
	private const HANDLER_URL = "<web_service_address>";
	private const AUTH_LOGIN = "<web_service_login>";
	private const AUTH_PASS = "<web_service_pass";
	private const SYSTEM_USER_ID = 0;
	private const TO_USER_ID = "<user_id>";

	public static function SendB24TaskTo1c($iTaskId)
	{
		CModule::IncludeModule("tasks");
		$dbRes = CTasks::GetByID($iTaskId);
		$arTask = $dbRes->Fetch();
		if (!in_array(self::B_24_TAG, $arTask['TAGS'])) {
			return;
		} else {
			$iResponsibleId = $arTask["RESPONSIBLE_ID"];
			$iCreatedById = $arTask["CREATED_BY"];
			$iHours = $arTask["TIME_ESTIMATE"] / 3600;
			$sStartDate = $arTask["CREATED_DATE"];
			$sEndDate = $arTask["DEADLINE"];
			$sDescription = $arTask["DESCRIPTION"];
			$iSum = $arTask[self::USER_FIELD_SUMM_TITLE];
			$sCompanyId = '';
			foreach ($arTask["UF_CRM_TASK"] as $crmEntity) {
				if (substr($crmEntity, 0, 2) === "CO") {
					$sCompanyId = substr($crmEntity, 3);
				}
			}
			$sCompanyTitle = CCrmCompany::GetById($sCompanyId)["TITLE"];
			$req = new \Bitrix\Crm\EntityRequisite();
			$dbRes = $req->getList(
				[
					"filter" =>
					[
						"ENTITY_ID" => $sCompanyId,
						"ENTITY_TYPE_ID" => CCrmOwnerType::Company,
					],
					"select" =>
					[
						"RQ_INN"
					]
				]
			);
			$sTaxId = $dbRes->Fetch()["RQ_INN"];
			$sUrl = self::HANDLER_URL . "?hour=" . $iHours . "&sum=" . $iSum . "&client_inn=" . $sTaxId;
			$sUrl = $sUrl . "&client_name=" . urlencode($sCompanyTitle) . "&id_responsible=" . $iResponsibleId;
			$sUrl = $sUrl . "&id_manager=" . $iCreatedById . "&start_date=" . urlencode($sStartDate);
			$sUrl = $sUrl . "&end_date=" . urlencode($sEndDate) . "&description=" . urlencode($sDescription);
			$httpClient = new \Bitrix\Main\Web\HttpClient();
			$httpClient->setAuthorization(self::AUTH_LOGIN, self::AUTH_PASS);
			$httpClient->post($sUrl);
			if ($httpClient->getStatus() != 200) {
				define("LOG_FILENAME", $_SERVER["DOCUMENT_ROOT"] . "/logs/task/log.txt");
				$sIMMessage = "Ошибка синхронизации задачи " . $arTask["TITLE"] . " с 1с!";
				AddMessage2Log($sIMMessage . "Статус= " . $httpClient->getStatus() . " Обработчик на стороне 1с передал следующий ответ: " . $httpClient->getResult(), 'tasks');
			} else {
				$sIMMessage = "Задача " . $arTask["TITLE"] . " успешно синхронизирована с 1с!";
			}
			\Bitrix\Main\Loader::includeModule('im');
			$arFields = array(
				"FROM_USER_ID" => self::SYSTEM_USER_ID,
				"SYSTEM" => "Y",
				"MESSAGE"  => $sIMMessage,
				"MESSAGE_TYPE" => IM_MESSAGE_SYSTEM,
				"TO_USER_ID" => self::TO_USER_ID,
			);
			CIMNotify::Add($arFields);
		}
	}

	public static function ValidateB24Task($task)
	{
		if (!in_array(self::B_24_TAG, $task['TAGS'])) {
			return true;
		} else {
			if (!$task['UF_CRM_TASK']) {
				$GLOBALS['APPLICATION']->throwException('Не заполнено поле связки с элементом CRM!');
				return false;
			} else {
				$bCompanyPresentAsCrmEntity = false;
				foreach ($task['UF_CRM_TASK'] as $crmEntity) {
					if (substr($crmEntity, 0, 2) === "CO") {
						$bCompanyPresentAsCrmEntity = true;
					}
				}
				if (!$bCompanyPresentAsCrmEntity) {
					$GLOBALS['APPLICATION']->throwException('В поле связки с элементом CRM должна быть указана хоть одна компания!');
					return false;
				}
			}
			if ($task['TIME_ESTIMATE'] == "0") {
				$GLOBALS['APPLICATION']->throwException('Не заполнена предполагаемая продолжительность задачи!!');
				return false;
			}
			if (!$task['DEADLINE']) {
				$GLOBALS['APPLICATION']->throwException('Не заполнена предполагаемая дата завершения задачи!!');
				return false;
			}
			if ($task[self::USER_FIELD_SUMM_TITLE] == "0" || is_null($task[self::USER_FIELD_SUMM_TITLE])) {
				$GLOBALS['APPLICATION']->throwException('Не заполнена предполагаемая сумма задачи!!');
				return false;
			}
			return true;
		}
	}
}
