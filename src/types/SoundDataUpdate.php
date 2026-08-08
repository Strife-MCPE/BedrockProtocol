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

use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pmmp\encoding\LE;
use pmmp\encoding\VarInt;
use pocketmine\network\mcpe\protocol\PacketDecodeException;

/**
 * Only used by 1.26.40+ (ProtocolInfo::PROTOCOL_1_26_40), in ClientboundUpdateSoundDataPacket.
 */
final class SoundDataUpdate{

	public const TYPE_STOP = 0;
	public const TYPE_SET_VOLUME = 1;
	public const TYPE_SET_PITCH = 2;
	public const TYPE_FADE = 3;
	public const TYPE_SEEK_TO = 4;
	public const TYPE_PAUSE = 5;
	public const TYPE_RESUME = 6;

	public function __construct(
		private int $type,
		private float $volume = 0.0,
		private float $pitch = 0.0,
		private float $fadeDuration = 0.0,
		private float $fadeTargetVolume = 0.0,
		private float $seekToSeconds = 0.0
	){}

	public function getType() : int{ return $this->type; }

	public function getVolume() : float{ return $this->volume; }

	public function getPitch() : float{ return $this->pitch; }

	public function getFadeDuration() : float{ return $this->fadeDuration; }

	public function getFadeTargetVolume() : float{ return $this->fadeTargetVolume; }

	public function getSeekToSeconds() : float{ return $this->seekToSeconds; }

	public static function read(ByteBufferReader $in) : self{
		$type = VarInt::readUnsignedInt($in);
		return match($type){
			self::TYPE_STOP, self::TYPE_PAUSE, self::TYPE_RESUME => new self($type),
			self::TYPE_SET_VOLUME => new self($type, volume: LE::readFloat($in)),
			self::TYPE_SET_PITCH => new self($type, pitch: LE::readFloat($in)),
			self::TYPE_FADE => new self($type, fadeDuration: LE::readFloat($in), fadeTargetVolume: LE::readFloat($in)),
			self::TYPE_SEEK_TO => new self($type, seekToSeconds: LE::readFloat($in)),
			default => throw new PacketDecodeException("Unknown sound data update type $type")
		};
	}

	public function write(ByteBufferWriter $out) : void{
		VarInt::writeUnsignedInt($out, $this->type);
		switch($this->type){
			case self::TYPE_STOP:
			case self::TYPE_PAUSE:
			case self::TYPE_RESUME:
				break;
			case self::TYPE_SET_VOLUME:
				LE::writeFloat($out, $this->volume);
				break;
			case self::TYPE_SET_PITCH:
				LE::writeFloat($out, $this->pitch);
				break;
			case self::TYPE_FADE:
				LE::writeFloat($out, $this->fadeDuration);
				LE::writeFloat($out, $this->fadeTargetVolume);
				break;
			case self::TYPE_SEEK_TO:
				LE::writeFloat($out, $this->seekToSeconds);
				break;
			default:
				throw new \InvalidArgumentException("Unknown sound data update type $this->type");
		}
	}
}
