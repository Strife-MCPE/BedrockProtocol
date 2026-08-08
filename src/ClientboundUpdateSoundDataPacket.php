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

use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pmmp\encoding\LE;
use pocketmine\network\mcpe\protocol\serializer\CommonTypes;
use pocketmine\network\mcpe\protocol\types\SoundDataUpdate;
use function count;

class ClientboundUpdateSoundDataPacket extends DataPacket implements ClientboundPacket{
	public const NETWORK_ID = ProtocolInfo::CLIENTBOUND_UPDATE_SOUND_DATA_PACKET;

	public const UPDATE_SLOTS = 7;

	private int $serverSoundHandle;
	/** Only used by < 1.26.40 */
	private string $soundEvent = "";
	/**
	 * Only used by >= 1.26.40 - exactly UPDATE_SLOTS slots, each of which may be null
	 * @var (SoundDataUpdate|null)[]
	 * @phpstan-var array<int, SoundDataUpdate|null>
	 */
	private array $updates = [];

	/**
	 * @param (SoundDataUpdate|null)[] $updates only used by >= 1.26.40
	 * @phpstan-param array<int, SoundDataUpdate|null> $updates
	 */
	public static function create(int $serverSoundHandle, string $soundEvent, array $updates = []) : self{
		if(count($updates) !== 0 && count($updates) !== self::UPDATE_SLOTS){
			throw new \InvalidArgumentException("Expected exactly " . self::UPDATE_SLOTS . " update slots");
		}
		$result = new self;
		$result->serverSoundHandle = $serverSoundHandle;
		$result->soundEvent = $soundEvent;
		$result->updates = $updates;
		return $result;
	}

	public function getServerSoundHandle() : int{ return $this->serverSoundHandle; }

	public function getSoundEvent() : string{ return $this->soundEvent; }

	/** @return array<int, SoundDataUpdate|null> */
	public function getUpdates() : array{ return $this->updates; }

	protected function decodePayload(ByteBufferReader $in, int $protocolId) : void{
		$this->serverSoundHandle = LE::readUnsignedLong($in);
		if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
			$this->updates = [];
			for($i = 0; $i < self::UPDATE_SLOTS; ++$i){
				$this->updates[$i] = CommonTypes::readOptional($in, SoundDataUpdate::read(...));
			}
		}else{
			$this->soundEvent = CommonTypes::getString($in);
		}
	}

	protected function encodePayload(ByteBufferWriter $out, int $protocolId) : void{
		LE::writeUnsignedLong($out, $this->serverSoundHandle);
		if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
			for($i = 0; $i < self::UPDATE_SLOTS; ++$i){
				CommonTypes::writeOptional($out, $this->updates[$i] ?? null, fn(ByteBufferWriter $out, SoundDataUpdate $update) => $update->write($out));
			}
		}else{
			CommonTypes::putString($out, $this->soundEvent);
		}
	}

	public function handle(PacketHandlerInterface $handler) : bool{
		return $handler->handleClientboundUpdateSoundData($this);
	}
}
