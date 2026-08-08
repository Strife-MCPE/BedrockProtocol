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
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\network\mcpe\protocol\types\DimensionIds;
use pocketmine\network\mcpe\protocol\types\MapDecoration;
use pocketmine\network\mcpe\protocol\types\MapImage;
use pocketmine\network\mcpe\protocol\types\MapTrackedObject;
use pocketmine\utils\Binary;
use function count;

class ClientboundMapItemDataPacket extends DataPacket implements ClientboundPacket{
	public const NETWORK_ID = ProtocolInfo::CLIENTBOUND_MAP_ITEM_DATA_PACKET;

	public const BITFLAG_TEXTURE_UPDATE = 0x02;
	public const BITFLAG_DECORATION_UPDATE = 0x04;
	public const BITFLAG_MAP_CREATION = 0x08;

	public int $mapId;
	public int $type;
	public int $dimensionId = DimensionIds::OVERWORLD;
	public bool $isLocked = false;
	public BlockPosition $origin;

	/** @var int[] */
	public array $parentMapIds = [];
	public int $scale;

	/** @var MapTrackedObject[] */
	public array $trackedEntities = [];
	/** @var MapDecoration[] */
	public array $decorations = [];

	public int $xOffset = 0;
	public int $yOffset = 0;
	public ?MapImage $colors = null;

	protected function decodePayload(ByteBufferReader $in, int $protocolId) : void{
		$this->mapId = CommonTypes::getActorUniqueId($in);
		if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
			$this->decodePayload1_26_40($in, $protocolId);
			return;
		}
		$this->type = VarInt::readUnsignedInt($in);
		$this->dimensionId = Byte::readUnsigned($in);
		$this->isLocked = CommonTypes::getBool($in);
		$this->origin = CommonTypes::getBlockPosition($in);

		if(($this->type & self::BITFLAG_MAP_CREATION) !== 0){
			$count = VarInt::readUnsignedInt($in);
			for($i = 0; $i < $count; ++$i){
				$this->parentMapIds[] = CommonTypes::getActorUniqueId($in);
			}
		}

		if(($this->type & (self::BITFLAG_MAP_CREATION | self::BITFLAG_DECORATION_UPDATE | self::BITFLAG_TEXTURE_UPDATE)) !== 0){ //Decoration bitflag or colour bitflag
			$this->scale = Byte::readUnsigned($in);
		}

		if(($this->type & self::BITFLAG_DECORATION_UPDATE) !== 0){
			for($i = 0, $count = VarInt::readUnsignedInt($in); $i < $count; ++$i){
				$object = new MapTrackedObject();
				$object->type = LE::readUnsignedInt($in);
				if($object->type === MapTrackedObject::TYPE_BLOCK){
					$object->blockPosition = CommonTypes::getBlockPosition($in, $protocolId >= ProtocolInfo::PROTOCOL_1_26_10);
				}elseif($object->type === MapTrackedObject::TYPE_ENTITY){
					$object->actorUniqueId = CommonTypes::getActorUniqueId($in);
				}else{
					throw new PacketDecodeException("Unknown map object type $object->type");
				}
				$this->trackedEntities[] = $object;
			}

			for($i = 0, $count = VarInt::readUnsignedInt($in); $i < $count; ++$i){
				$icon = Byte::readUnsigned($in);
				$rotation = Byte::readUnsigned($in);
				$xOffset = Byte::readUnsigned($in);
				$yOffset = Byte::readUnsigned($in);
				$label = CommonTypes::getString($in);
				$color = Color::fromRGBA(Binary::flipIntEndianness(VarInt::readUnsignedInt($in)));
				$this->decorations[] = new MapDecoration($icon, $rotation, $xOffset, $yOffset, $label, $color);
			}
		}

		if(($this->type & self::BITFLAG_TEXTURE_UPDATE) !== 0){
			$width = VarInt::readSignedInt($in);
			$height = VarInt::readSignedInt($in);
			$this->xOffset = VarInt::readSignedInt($in);
			$this->yOffset = VarInt::readSignedInt($in);

			$count = VarInt::readUnsignedInt($in);
			if($count !== $width * $height){
				throw new PacketDecodeException("Expected colour count of " . ($height * $width) . " (height $height * width $width), got $count");
			}

			$this->colors = MapImage::decode($in, $height, $width, $protocolId);
		}
	}

	private function decodePayload1_26_40(ByteBufferReader $in, int $protocolId) : void{
		$this->dimensionId = Byte::readUnsigned($in);
		$this->isLocked = CommonTypes::getBool($in);
		$this->origin = CommonTypes::getBlockPosition($in);

		$this->type = 0;
		if(CommonTypes::getBool($in)){
			$this->type |= self::BITFLAG_MAP_CREATION;
			$count = VarInt::readUnsignedInt($in);
			for($i = 0; $i < $count; ++$i){
				$this->parentMapIds[] = CommonTypes::getActorUniqueId($in);
			}
		}

		if(CommonTypes::getBool($in)){
			$this->scale = Byte::readUnsigned($in);
		}

		if(CommonTypes::getBool($in)){
			$this->type |= self::BITFLAG_DECORATION_UPDATE;
			for($i = 0, $count = VarInt::readUnsignedInt($in); $i < $count; ++$i){
				$object = new MapTrackedObject();
				$object->type = LE::readUnsignedInt($in);
				$actorUniqueId = CommonTypes::readOptional($in, CommonTypes::getActorUniqueId(...));
				$blockPosition = CommonTypes::readOptional($in, CommonTypes::getBlockPosition(...));
				if($object->type === MapTrackedObject::TYPE_BLOCK){
					$object->blockPosition = $blockPosition ?? throw new PacketDecodeException("Expected block position for block tracked object");
				}elseif($object->type === MapTrackedObject::TYPE_ENTITY){
					$object->actorUniqueId = $actorUniqueId ?? throw new PacketDecodeException("Expected actor unique ID for entity tracked object");
				}else{
					throw new PacketDecodeException("Unknown map object type $object->type");
				}
				$this->trackedEntities[] = $object;
			}
		}

		if(CommonTypes::getBool($in)){
			$this->type |= self::BITFLAG_DECORATION_UPDATE;
			for($i = 0, $count = VarInt::readUnsignedInt($in); $i < $count; ++$i){
				$icon = Byte::readUnsigned($in);
				$rotation = Byte::readUnsigned($in);
				$xOffset = Byte::readUnsigned($in);
				$yOffset = Byte::readUnsigned($in);
				$label = CommonTypes::getString($in);
				$color = Color::fromARGB(LE::readUnsignedInt($in)); //B, G, R, A byte order
				$this->decorations[] = new MapDecoration($icon, $rotation, $xOffset, $yOffset, $label, $color);
			}
		}

		$width = CommonTypes::readOptional($in, VarInt::readSignedInt(...));
		$height = CommonTypes::readOptional($in, VarInt::readSignedInt(...));
		$this->xOffset = CommonTypes::readOptional($in, VarInt::readSignedInt(...)) ?? 0;
		$this->yOffset = CommonTypes::readOptional($in, VarInt::readSignedInt(...)) ?? 0;
		if(CommonTypes::getBool($in)){
			$this->type |= self::BITFLAG_TEXTURE_UPDATE;
			if($width === null || $height === null){
				throw new PacketDecodeException("Expected width and height for map texture update");
			}
			$count = VarInt::readUnsignedInt($in);
			if($count !== $width * $height){
				throw new PacketDecodeException("Expected colour count of " . ($height * $width) . " (height $height * width $width), got $count");
			}

			$this->colors = MapImage::decode($in, $height, $width, $protocolId);
		}
	}

	protected function encodePayload(ByteBufferWriter $out, int $protocolId) : void{
		CommonTypes::putActorUniqueId($out, $this->mapId);
		if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
			$this->encodePayload1_26_40($out, $protocolId);
			return;
		}

		$type = 0;
		if(($parentMapIdsCount = count($this->parentMapIds)) > 0){
			$type |= self::BITFLAG_MAP_CREATION;
		}
		if(($decorationCount = count($this->decorations)) > 0){
			$type |= self::BITFLAG_DECORATION_UPDATE;
		}
		if($this->colors !== null){
			$type |= self::BITFLAG_TEXTURE_UPDATE;
		}

		VarInt::writeUnsignedInt($out, $type);
		Byte::writeUnsigned($out, $this->dimensionId);
		CommonTypes::putBool($out, $this->isLocked);
		CommonTypes::putBlockPosition($out, $this->origin);

		if(($type & self::BITFLAG_MAP_CREATION) !== 0){
			VarInt::writeUnsignedInt($out, $parentMapIdsCount);
			foreach($this->parentMapIds as $parentMapId){
				CommonTypes::putActorUniqueId($out, $parentMapId);
			}
		}

		if(($type & (self::BITFLAG_MAP_CREATION | self::BITFLAG_TEXTURE_UPDATE | self::BITFLAG_DECORATION_UPDATE)) !== 0){
			Byte::writeUnsigned($out, $this->scale);
		}

		if(($type & self::BITFLAG_DECORATION_UPDATE) !== 0){
			VarInt::writeUnsignedInt($out, count($this->trackedEntities));
			foreach($this->trackedEntities as $object){
				LE::writeUnsignedInt($out, $object->type);
				if($object->type === MapTrackedObject::TYPE_BLOCK){
					CommonTypes::putBlockPosition($out, $object->blockPosition, $protocolId >= ProtocolInfo::PROTOCOL_1_26_10);
				}elseif($object->type === MapTrackedObject::TYPE_ENTITY){
					CommonTypes::putActorUniqueId($out, $object->actorUniqueId);
				}else{
					throw new \InvalidArgumentException("Unknown map object type $object->type");
				}
			}

			VarInt::writeUnsignedInt($out, $decorationCount);
			foreach($this->decorations as $decoration){
				Byte::writeUnsigned($out, $decoration->getIcon());
				Byte::writeUnsigned($out, $decoration->getRotation());
				Byte::writeUnsigned($out, $decoration->getXOffset());
				Byte::writeUnsigned($out, $decoration->getYOffset());
				CommonTypes::putString($out, $decoration->getLabel());
				VarInt::writeUnsignedInt($out, Binary::flipIntEndianness($decoration->getColor()->toRGBA()));
			}
		}

		if($this->colors !== null){
			VarInt::writeSignedInt($out, $this->colors->getWidth());
			VarInt::writeSignedInt($out, $this->colors->getHeight());
			VarInt::writeSignedInt($out, $this->xOffset);
			VarInt::writeSignedInt($out, $this->yOffset);

			VarInt::writeUnsignedInt($out, $this->colors->getWidth() * $this->colors->getHeight()); //list count, but we handle it as a 2D array... thanks for the confusion mojang

			$this->colors->encode($out, $protocolId);
		}
	}

	private function encodePayload1_26_40(ByteBufferWriter $out, int $protocolId) : void{
		Byte::writeUnsigned($out, $this->dimensionId);
		CommonTypes::putBool($out, $this->isLocked);
		CommonTypes::putBlockPosition($out, $this->origin);

		$parentMapIdsCount = count($this->parentMapIds);
		CommonTypes::putBool($out, $parentMapIdsCount > 0);
		if($parentMapIdsCount > 0){
			VarInt::writeUnsignedInt($out, $parentMapIdsCount);
			foreach($this->parentMapIds as $parentMapId){
				CommonTypes::putActorUniqueId($out, $parentMapId);
			}
		}

		$anySection = $parentMapIdsCount > 0 || count($this->decorations) > 0 || $this->colors !== null;
		CommonTypes::putBool($out, $anySection);
		if($anySection){
			Byte::writeUnsigned($out, $this->scale);
		}

		$decorationCount = count($this->decorations);
		$trackedCount = count($this->trackedEntities);
		CommonTypes::putBool($out, $trackedCount > 0);
		if($trackedCount > 0){
			VarInt::writeUnsignedInt($out, $trackedCount);
			foreach($this->trackedEntities as $object){
				LE::writeUnsignedInt($out, $object->type);
				CommonTypes::writeOptional($out, $object->type === MapTrackedObject::TYPE_ENTITY ? $object->actorUniqueId : null, CommonTypes::putActorUniqueId(...));
				CommonTypes::writeOptional($out, $object->type === MapTrackedObject::TYPE_BLOCK ? $object->blockPosition : null, CommonTypes::putBlockPosition(...));
			}
		}

		CommonTypes::putBool($out, $decorationCount > 0);
		if($decorationCount > 0){
			VarInt::writeUnsignedInt($out, $decorationCount);
			foreach($this->decorations as $decoration){
				Byte::writeUnsigned($out, $decoration->getIcon());
				Byte::writeUnsigned($out, $decoration->getRotation());
				Byte::writeUnsigned($out, $decoration->getXOffset());
				Byte::writeUnsigned($out, $decoration->getYOffset());
				CommonTypes::putString($out, $decoration->getLabel());
				LE::writeUnsignedInt($out, $decoration->getColor()->toARGB()); //B, G, R, A byte order
			}
		}

		CommonTypes::writeOptional($out, $this->colors?->getWidth(), VarInt::writeSignedInt(...));
		CommonTypes::writeOptional($out, $this->colors?->getHeight(), VarInt::writeSignedInt(...));
		CommonTypes::writeOptional($out, $this->colors !== null ? $this->xOffset : null, VarInt::writeSignedInt(...));
		CommonTypes::writeOptional($out, $this->colors !== null ? $this->yOffset : null, VarInt::writeSignedInt(...));
		CommonTypes::putBool($out, $this->colors !== null);
		if($this->colors !== null){
			VarInt::writeUnsignedInt($out, $this->colors->getWidth() * $this->colors->getHeight()); //list count, but we handle it as a 2D array... thanks for the confusion mojang

			$this->colors->encode($out, $protocolId);
		}
	}

	public function handle(PacketHandlerInterface $handler) : bool{
		return $handler->handleClientboundMapItemData($this);
	}
}
