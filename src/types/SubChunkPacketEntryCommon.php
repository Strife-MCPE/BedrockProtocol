<?php

/*
 * This file is part of BedrockProtocol.
 * Copyright (C) 2014-2022 PocketMine Team <https://github.com/pmmp/BedrockProtocol>
 *
 * BedrockProtocol is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

declare(strict_types=1);

namespace pocketmine\network\mcpe\protocol\types;

use pmmp\encoding\Byte;
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pocketmine\network\mcpe\protocol\PacketDecodeException;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\serializer\CommonTypes;

final class SubChunkPacketEntryCommon{

	public function __construct(
		private SubChunkPositionOffset $offset,
		private int $requestResult,
		private string $terrainData,
		private ?SubChunkPacketHeightMapInfo $heightMap,
		private ?SubChunkPacketHeightMapInfo $renderHeightMap
	){}

	public function getOffset() : SubChunkPositionOffset{ return $this->offset; }

	public function getRequestResult() : int{ return $this->requestResult; }

	public function getTerrainData() : string{ return $this->terrainData; }

	public function getHeightMap() : ?SubChunkPacketHeightMapInfo{ return $this->heightMap; }

	public function getRenderHeightMap() : ?SubChunkPacketHeightMapInfo{ return $this->renderHeightMap; }

	public static function read(ByteBufferReader $in, int $protocolId, bool $cacheEnabled) : self{
		$offset = SubChunkPositionOffset::read($in);

		$requestResult = Byte::readUnsigned($in);

		//1.26.40+: the terrain data and the heightmap payloads are all presence-bool prefixed
		if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
			$data = CommonTypes::getBool($in) ? CommonTypes::getString($in) : "";
		}else{
			$data = !$cacheEnabled || $requestResult !== SubChunkRequestResult::SUCCESS_ALL_AIR ? CommonTypes::getString($in) : "";
		}

		$heightMapDataType = Byte::readUnsigned($in);
		if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
			$heightMapPayload = CommonTypes::getBool($in) ? SubChunkPacketHeightMapInfo::read($in) : null;
			$heightMapData = match ($heightMapDataType) {
				SubChunkPacketHeightMapType::NO_DATA => null,
				SubChunkPacketHeightMapType::DATA => $heightMapPayload ?? throw new PacketDecodeException("Expected heightmap data to be present"),
				SubChunkPacketHeightMapType::ALL_TOO_HIGH => SubChunkPacketHeightMapInfo::allTooHigh(),
				SubChunkPacketHeightMapType::ALL_TOO_LOW => SubChunkPacketHeightMapInfo::allTooLow(),
				default => throw new PacketDecodeException("Unknown heightmap data type $heightMapDataType")
			};
		}else{
			$heightMapData = match ($heightMapDataType) {
				SubChunkPacketHeightMapType::NO_DATA => null,
				SubChunkPacketHeightMapType::DATA => SubChunkPacketHeightMapInfo::read($in),
				SubChunkPacketHeightMapType::ALL_TOO_HIGH => SubChunkPacketHeightMapInfo::allTooHigh(),
				SubChunkPacketHeightMapType::ALL_TOO_LOW => SubChunkPacketHeightMapInfo::allTooLow(),
				default => throw new PacketDecodeException("Unknown heightmap data type $heightMapDataType")
			};
		}

		if($protocolId >= ProtocolInfo::PROTOCOL_1_21_90){
			$renderHeightMapDataType = Byte::readUnsigned($in);
			if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
				$renderHeightMapPayload = CommonTypes::getBool($in) ? SubChunkPacketHeightMapInfo::read($in) : null;
				$renderHeightMapData = match ($renderHeightMapDataType) {
					SubChunkPacketHeightMapType::NO_DATA => null,
					SubChunkPacketHeightMapType::DATA => $renderHeightMapPayload ?? throw new PacketDecodeException("Expected render heightmap data to be present"),
					SubChunkPacketHeightMapType::ALL_TOO_HIGH => SubChunkPacketHeightMapInfo::allTooHigh(),
					SubChunkPacketHeightMapType::ALL_TOO_LOW => SubChunkPacketHeightMapInfo::allTooLow(),
					SubChunkPacketHeightMapType::ALL_COPIED => $heightMapData,
					default => throw new PacketDecodeException("Unknown render heightmap data type $renderHeightMapDataType")
				};
			}else{
				$renderHeightMapData = match ($renderHeightMapDataType) {
					SubChunkPacketHeightMapType::NO_DATA => null,
					SubChunkPacketHeightMapType::DATA => SubChunkPacketHeightMapInfo::read($in),
					SubChunkPacketHeightMapType::ALL_TOO_HIGH => SubChunkPacketHeightMapInfo::allTooHigh(),
					SubChunkPacketHeightMapType::ALL_TOO_LOW => SubChunkPacketHeightMapInfo::allTooLow(),
					SubChunkPacketHeightMapType::ALL_COPIED => $heightMapData,
					default => throw new PacketDecodeException("Unknown render heightmap data type $renderHeightMapDataType")
				};
			}
		}

		return new self(
			$offset,
			$requestResult,
			$data,
			$heightMapData,
			$renderHeightMapData ?? null,
		);
	}

	public function write(ByteBufferWriter $out, int $protocolId, bool $cacheEnabled) : void{
		//1.26.40+: the terrain data and the heightmap payloads are all presence-bool prefixed
		$this->offset->write($out);

		Byte::writeUnsigned($out, $this->requestResult);

		$dataPresent = !$cacheEnabled || $this->requestResult !== SubChunkRequestResult::SUCCESS_ALL_AIR;
		if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
			CommonTypes::putBool($out, $dataPresent);
		}
		if($dataPresent){
			CommonTypes::putString($out, $this->terrainData);
		}

		if($this->heightMap === null){
			Byte::writeUnsigned($out, SubChunkPacketHeightMapType::NO_DATA);
			if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
				CommonTypes::putBool($out, false);
			}
		}elseif($this->heightMap->isAllTooLow()){
			Byte::writeUnsigned($out, SubChunkPacketHeightMapType::ALL_TOO_LOW);
			if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
				CommonTypes::putBool($out, false);
			}
		}elseif($this->heightMap->isAllTooHigh()){
			Byte::writeUnsigned($out, SubChunkPacketHeightMapType::ALL_TOO_HIGH);
			if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
				CommonTypes::putBool($out, false);
			}
		}else{
			$heightMapData = $this->heightMap; //avoid PHPStan purity issue
			Byte::writeUnsigned($out, SubChunkPacketHeightMapType::DATA);
			if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
				CommonTypes::putBool($out, true);
			}
			$heightMapData->write($out);
		}

		if($protocolId >= ProtocolInfo::PROTOCOL_1_21_90){
			if($this->renderHeightMap === null){
				Byte::writeUnsigned($out, SubChunkPacketHeightMapType::ALL_COPIED);
				if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
					CommonTypes::putBool($out, false);
				}
			}elseif($this->renderHeightMap->isAllTooLow()){
				Byte::writeUnsigned($out, SubChunkPacketHeightMapType::ALL_TOO_LOW);
				if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
					CommonTypes::putBool($out, false);
				}
			}elseif($this->renderHeightMap->isAllTooHigh()){
				Byte::writeUnsigned($out, SubChunkPacketHeightMapType::ALL_TOO_HIGH);
				if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
					CommonTypes::putBool($out, false);
				}
			}else{
				$renderHeightMapData = $this->renderHeightMap; //avoid PHPStan purity issue
				Byte::writeUnsigned($out, SubChunkPacketHeightMapType::DATA);
				if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
					CommonTypes::putBool($out, true);
				}
				$renderHeightMapData->write($out);
			}
		}
	}
}
