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

namespace pocketmine\network\mcpe\protocol;

use pmmp\encoding\Byte;
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pmmp\encoding\LE;
use pmmp\encoding\VarInt;
use pocketmine\color\Color;
use pocketmine\network\mcpe\protocol\serializer\CommonTypes;
use pocketmine\network\mcpe\protocol\types\PlayerListEntry;
use function count;

class PlayerListPacket extends DataPacket implements ClientboundPacket{
	public const NETWORK_ID = ProtocolInfo::PLAYER_LIST_PACKET;

	public const TYPE_ADD = 0;
	public const TYPE_REMOVE = 1;

	public int $type;
	/** @var PlayerListEntry[] */
	public array $entries = [];

	/**
	 * @generate-create-func
	 * @param PlayerListEntry[] $entries
	 */
	private static function create(int $type, array $entries) : self{
		$result = new self;
		$result->type = $type;
		$result->entries = $entries;
		return $result;
	}

	/**
	 * @param PlayerListEntry[] $entries
	 */
	public static function add(array $entries) : self{
		return self::create(self::TYPE_ADD, $entries);
	}

	/**
	 * @param PlayerListEntry[] $entries
	 */
	public static function remove(array $entries) : self{
		return self::create(self::TYPE_REMOVE, $entries);
	}

	protected function decodePayload(ByteBufferReader $in, int $protocolId) : void{
		if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
			//1.26.40 made the action per-entry (variant + legacy action byte) and removed the trailing
			//skin-verified bool array (the trusted flag moved into the skin serialization itself)
			$count = VarInt::readUnsignedInt($in);
			for($i = 0; $i < $count; ++$i){
				$entry = new PlayerListEntry();

				$variant = VarInt::readUnsignedInt($in);
				$legacyAction = Byte::readUnsigned($in);
				$this->type = match($variant){
					1 => self::TYPE_ADD,
					0 => self::TYPE_REMOVE,
					default => throw new PacketDecodeException("Unknown player list entry variant $variant")
				};
				if($legacyAction !== $this->type){
					throw new PacketDecodeException("Player list legacy action $legacyAction does not match variant $variant");
				}

				$entry->uuid = CommonTypes::getUUID($in);
				if($this->type === self::TYPE_ADD){
					$entry->actorUniqueId = CommonTypes::getActorUniqueId($in);
					$entry->username = CommonTypes::getString($in);
					$entry->xboxUserId = CommonTypes::getString($in);
					$entry->platformChatId = CommonTypes::getString($in);
					$entry->buildPlatform = LE::readSignedInt($in);
					$entry->skinData = CommonTypes::getSkin($in, $protocolId);
					$entry->isTeacher = CommonTypes::getBool($in);
					$entry->isHost = CommonTypes::getBool($in);
					$entry->isSubClient = CommonTypes::getBool($in);
					$entry->color = Color::fromARGB(LE::readUnsignedInt($in));
				}

				$this->entries[$i] = $entry;
			}
			return;
		}
		$this->type = Byte::readUnsigned($in);
		$count = VarInt::readUnsignedInt($in);
		for($i = 0; $i < $count; ++$i){
			$entry = new PlayerListEntry();

			if($this->type === self::TYPE_ADD){
				$entry->uuid = CommonTypes::getUUID($in);
				$entry->actorUniqueId = CommonTypes::getActorUniqueId($in);
				$entry->username = CommonTypes::getString($in);
				$entry->xboxUserId = CommonTypes::getString($in);
				$entry->platformChatId = CommonTypes::getString($in);
				$entry->buildPlatform = LE::readSignedInt($in);
				$entry->skinData = CommonTypes::getSkin($in, $protocolId);
				$entry->isTeacher = CommonTypes::getBool($in);
				$entry->isHost = CommonTypes::getBool($in);
				if($protocolId >= ProtocolInfo::PROTOCOL_1_20_60){
					$entry->isSubClient = CommonTypes::getBool($in);
					if($protocolId >= ProtocolInfo::PROTOCOL_1_21_80){
						$entry->color = Color::fromARGB(LE::readUnsignedInt($in));
					}
				}
			}else{
				$entry->uuid = CommonTypes::getUUID($in);
			}

			$this->entries[$i] = $entry;
		}
		if($this->type === self::TYPE_ADD){
			for($i = 0; $i < $count; ++$i){
				$this->entries[$i]->skinData->setVerified(CommonTypes::getBool($in));
			}
		}
	}

	protected function encodePayload(ByteBufferWriter $out, int $protocolId) : void{
		if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
			VarInt::writeUnsignedInt($out, count($this->entries));
			foreach($this->entries as $entry){
				VarInt::writeUnsignedInt($out, $this->type === self::TYPE_ADD ? 1 : 0);
				Byte::writeUnsigned($out, $this->type);
				CommonTypes::putUUID($out, $entry->uuid);
				if($this->type === self::TYPE_ADD){
					CommonTypes::putActorUniqueId($out, $entry->actorUniqueId);
					CommonTypes::putString($out, $entry->username);
					CommonTypes::putString($out, $entry->xboxUserId);
					CommonTypes::putString($out, $entry->platformChatId);
					LE::writeSignedInt($out, $entry->buildPlatform);
					CommonTypes::putSkin($out, $protocolId, $entry->skinData);
					CommonTypes::putBool($out, $entry->isTeacher);
					CommonTypes::putBool($out, $entry->isHost);
					CommonTypes::putBool($out, $entry->isSubClient);
					LE::writeUnsignedInt($out, ($entry->color ?? new Color(255, 255, 255))->toARGB());
				}
			}
			return;
		}
		Byte::writeUnsigned($out, $this->type);
		VarInt::writeUnsignedInt($out, count($this->entries));
		foreach($this->entries as $entry){
			if($this->type === self::TYPE_ADD){
				CommonTypes::putUUID($out, $entry->uuid);
				CommonTypes::putActorUniqueId($out, $entry->actorUniqueId);
				CommonTypes::putString($out, $entry->username);
				CommonTypes::putString($out, $entry->xboxUserId);
				CommonTypes::putString($out, $entry->platformChatId);
				LE::writeSignedInt($out, $entry->buildPlatform);
				CommonTypes::putSkin($out, $protocolId, $entry->skinData);
				CommonTypes::putBool($out, $entry->isTeacher);
				CommonTypes::putBool($out, $entry->isHost);
				if($protocolId >= ProtocolInfo::PROTOCOL_1_20_60){
					CommonTypes::putBool($out, $entry->isSubClient);
					if($protocolId >= ProtocolInfo::PROTOCOL_1_21_80){
						LE::writeUnsignedInt($out, ($entry->color ?? new Color(255, 255, 255))->toARGB());
					}
				}
			}else{
				CommonTypes::putUUID($out, $entry->uuid);
			}
		}
		if($this->type === self::TYPE_ADD){
			foreach($this->entries as $entry){
				CommonTypes::putBool($out, $entry->skinData->isVerified());
			}
		}
	}

	public function handle(PacketHandlerInterface $handler) : bool{
		return $handler->handlePlayerList($this);
	}
}
