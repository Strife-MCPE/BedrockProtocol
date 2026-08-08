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

namespace pocketmine\network\mcpe\protocol\serializer;

use pmmp\encoding\BE;
use pmmp\encoding\Byte;
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pmmp\encoding\DataDecodeException;
use pmmp\encoding\LE;
use pmmp\encoding\VarInt;
use pocketmine\math\Vector2;
use pocketmine\math\Vector3;
use pocketmine\nbt\NbtDataException;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\TreeRoot;
use pocketmine\network\mcpe\protocol\PacketDecodeException;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\network\mcpe\protocol\types\BoolGameRule;
use pocketmine\network\mcpe\protocol\types\command\CommandOriginData;
use pocketmine\network\mcpe\protocol\types\entity\BlockPosMetadataProperty;
use pocketmine\network\mcpe\protocol\types\entity\ByteMetadataProperty;
use pocketmine\network\mcpe\protocol\types\entity\CompoundTagMetadataProperty;
use pocketmine\network\mcpe\protocol\types\entity\EntityLink;
use pocketmine\network\mcpe\protocol\types\entity\FloatMetadataProperty;
use pocketmine\network\mcpe\protocol\types\entity\IntMetadataProperty;
use pocketmine\network\mcpe\protocol\types\entity\LongMetadataProperty;
use pocketmine\network\mcpe\protocol\types\entity\MetadataProperty;
use pocketmine\network\mcpe\protocol\types\entity\ShortMetadataProperty;
use pocketmine\network\mcpe\protocol\types\entity\StringMetadataProperty;
use pocketmine\network\mcpe\protocol\types\entity\Vec3MetadataProperty;
use pocketmine\network\mcpe\protocol\types\FloatGameRule;
use pocketmine\network\mcpe\protocol\types\GameRule;
use pocketmine\network\mcpe\protocol\types\IntGameRule;
use pocketmine\network\mcpe\protocol\types\NullGameRule;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStack;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStackWrapper;
use pocketmine\network\mcpe\protocol\types\recipe\ComplexAliasItemDescriptor;
use pocketmine\network\mcpe\protocol\types\recipe\IntIdMetaItemDescriptor;
use pocketmine\network\mcpe\protocol\types\recipe\ItemDescriptorType;
use pocketmine\network\mcpe\protocol\types\recipe\MolangItemDescriptor;
use pocketmine\network\mcpe\protocol\types\recipe\RecipeIngredient;
use pocketmine\network\mcpe\protocol\types\recipe\StringIdMetaItemDescriptor;
use pocketmine\network\mcpe\protocol\types\recipe\TagItemDescriptor;
use pocketmine\network\mcpe\protocol\types\skin\PersonaPieceTintColor;
use pocketmine\network\mcpe\protocol\types\skin\PersonaSkinPiece;
use pocketmine\network\mcpe\protocol\types\skin\SkinAnimation;
use pocketmine\network\mcpe\protocol\types\skin\SkinData;
use pocketmine\network\mcpe\protocol\types\skin\SkinImage;
use pocketmine\network\mcpe\protocol\types\StructureEditorData;
use pocketmine\network\mcpe\protocol\types\StructureSettings;
use pocketmine\utils\Binary;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use function array_search;
use function array_slice;
use function count;
use function ctype_xdigit;
use function hexdec;
use function ltrim;
use function sprintf;
use function str_starts_with;
use function strlen;
use function strrev;
use function strtolower;
use function substr;

final class CommonTypes{

	private function __construct(){
		//NOOP
	}

	/** @throws DataDecodeException */
	public static function getString(ByteBufferReader $in) : string{
		return $in->readByteArray(VarInt::readUnsignedInt($in));
	}

	public static function putString(ByteBufferWriter $out, string $v) : void{
		VarInt::writeUnsignedInt($out, strlen($v));
		$out->writeByteArray($v);
	}

	/** @throws DataDecodeException */
	public static function getBool(ByteBufferReader $in) : bool{
		return Byte::readUnsigned($in) !== 0;
	}

	public static function putBool(ByteBufferWriter $out, bool $v) : void{
		Byte::writeUnsigned($out, $v ? 1 : 0);
	}

	/** @throws DataDecodeException */
	public static function getUUID(ByteBufferReader $in) : UuidInterface{
		//This is two little-endian longs: bytes 7-0 followed by bytes 15-8
		$p1 = strrev($in->readByteArray(8));
		$p2 = strrev($in->readByteArray(8));
		return Uuid::fromBytes($p1 . $p2);
	}

	public static function putUUID(ByteBufferWriter $out, UuidInterface $uuid) : void{
		$bytes = $uuid->getBytes();
		$out->writeByteArray(strrev(substr($bytes, 0, 8)));
		$out->writeByteArray(strrev(substr($bytes, 8, 8)));
	}

	/**
	 * 1.26.40+ block runtime IDs are signed on the wire (they are 32-bit hashes).
	 *
	 * @throws DataDecodeException
	 */
	public static function getBlockRuntimeId(ByteBufferReader $in) : int{
		return Binary::signInt(VarInt::readUnsignedInt($in));
	}

	public static function putBlockRuntimeId(ByteBufferWriter $out, int $blockRuntimeId) : void{
		VarInt::writeUnsignedInt($out, Binary::unsignInt($blockRuntimeId));
	}

	/** 1.26.40+ persona piece types are numeric IDs instead of the persona_* strings. */
	private const PERSONA_PIECE_TYPE_IDS = [
		"persona_skeleton" => 1,
		"persona_body" => 2,
		"persona_skin" => 3,
		"persona_bottom" => 4,
		"persona_feet" => 5,
		"persona_dress" => 6,
		"persona_top" => 7,
		"persona_high_pants" => 8,
		"persona_hand" => 9,
		"persona_outerwear" => 10,
		"persona_facial_hair" => 11,
		"persona_mouth" => 12,
		"persona_eyes" => 13,
		"persona_hair" => 14,
		"persona_hood" => 15,
		"persona_back" => 16,
		"persona_face_accessory" => 17,
		"persona_head" => 18,
		"persona_legs" => 19,
		"persona_left_leg" => 20,
		"persona_right_leg" => 21,
		"persona_arms" => 22,
		"persona_left_arm" => 23,
		"persona_right_arm" => 24,
		"persona_capes" => 25,
		"persona_classic_skin" => 26,
		"persona_emote" => 27,
	];
	private const PERSONA_PIECE_TYPE_UNSUPPORTED = 28;

	private static function hexColorToArgb(string $color) : int{
		$hex = ltrim($color, "#");
		if(strlen($hex) === 6){
			$hex = "ff" . $hex;
		}
		if(strlen($hex) !== 8 || !ctype_xdigit($hex)){
			return 0;
		}
		return (int) hexdec($hex);
	}

	private static function putBEARGB(ByteBufferWriter $out, int $argb) : void{
		$a = ($argb >> 24) & 0xff;
		$r = ($argb >> 16) & 0xff;
		$g = ($argb >> 8) & 0xff;
		$b = $argb & 0xff;
		//value on the wire = big-endian int32 of (A | R<<8 | G<<16 | B<<24), i.e. bytes B, G, R, A
		BE::writeUnsignedInt($out, (($b << 24) | ($g << 16) | ($r << 8) | $a));
	}

	/** @throws DataDecodeException */
	private static function getBEARGB(ByteBufferReader $in) : string{
		$val = BE::readUnsignedInt($in);
		$a = $val & 0xff;
		$r = ($val >> 8) & 0xff;
		$g = ($val >> 16) & 0xff;
		$b = ($val >> 24) & 0xff;
		return sprintf("#%02x%02x%02x%02x", $a, $r, $g, $b);
	}

	/** Converts persona_* tint piece names to the shorter 1.26.40+ wire names and back. */
	private static function tintPieceWireType(string $pieceType) : string{
		if($pieceType === "persona_hand"){
			return "hands";
		}
		return str_starts_with($pieceType, "persona_") ? substr($pieceType, strlen("persona_")) : $pieceType;
	}

	private static function tintPieceLoginType(string $wireType) : string{
		if($wireType === "hands"){
			return "persona_hand";
		}
		return $wireType === "unsupported" ? $wireType : "persona_" . $wireType;
	}

	/** @throws DataDecodeException */
	public static function getSkin(ByteBufferReader $in, int $protocolId) : SkinData{
		$skinId = self::getString($in);
		$skinPlayFabId = self::getString($in);
		$skinResourcePatch = self::getString($in);
		$skinData = self::getSkinImage($in);
		$animationCount = $protocolId >= ProtocolInfo::PROTOCOL_1_26_40 ? VarInt::readUnsignedInt($in) : LE::readUnsignedInt($in);
		$animations = [];
		for($i = 0; $i < $animationCount; ++$i){
			$skinImage = self::getSkinImage($in);
			if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
				$animationType = VarInt::readUnsignedInt($in);
				$animationFrames = LE::readFloat($in);
				$expressionType = VarInt::readUnsignedInt($in);
			}else{
				$animationType = LE::readUnsignedInt($in);
				$animationFrames = LE::readFloat($in);
				$expressionType = LE::readUnsignedInt($in);
			}
			$animations[] = new SkinAnimation($skinImage, $animationType, $animationFrames, $expressionType);
		}
		$capeData = self::getSkinImage($in);
		$geometryData = self::getString($in);
		$geometryDataVersion = self::getString($in);
		$animationData = self::getString($in);
		$capeId = self::getString($in);
		$fullSkinId = self::getString($in);
		if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
			$armSize = Byte::readUnsigned($in) === 0 ? SkinData::ARM_SIZE_SLIM : SkinData::ARM_SIZE_WIDE;
			$skinColor = self::getBEARGB($in);
			$personaPieceCount = VarInt::readUnsignedInt($in);
		}else{
			$armSize = self::getString($in);
			$skinColor = self::getString($in);
			$personaPieceCount = LE::readUnsignedInt($in);
		}
		$personaPieces = [];
		for($i = 0; $i < $personaPieceCount; ++$i){
			$pieceId = self::getString($in);
			if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
				$pieceTypeId = LE::readUnsignedInt($in);
				$pieceType = array_search($pieceTypeId, self::PERSONA_PIECE_TYPE_IDS, true);
				if($pieceType === false){
					$pieceType = "unsupported";
				}
				$packId = self::getUUID($in)->toString();
			}else{
				$pieceType = self::getString($in);
				$packId = self::getString($in);
			}
			$isDefaultPiece = self::getBool($in);
			$productId = self::getString($in);
			$personaPieces[] = new PersonaSkinPiece($pieceId, $pieceType, $packId, $isDefaultPiece, $productId);
		}
		$pieceTintColorCount = $protocolId >= ProtocolInfo::PROTOCOL_1_26_40 ? VarInt::readUnsignedInt($in) : LE::readUnsignedInt($in);
		$pieceTintColors = [];
		for($i = 0; $i < $pieceTintColorCount; ++$i){
			if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
				$pieceType = self::tintPieceLoginType(self::getString($in));
				$colors = [];
				for($j = 0; $j < 4; ++$j){
					$colors[] = self::getBEARGB($in);
				}
			}else{
				$pieceType = self::getString($in);
				$colorCount = LE::readUnsignedInt($in);
				$colors = [];
				for($j = 0; $j < $colorCount; ++$j){
					$colors[] = self::getString($in);
				}
			}
			$pieceTintColors[] = new PersonaPieceTintColor(
				$pieceType,
				$colors
			);
		}

		$premium = self::getBool($in);
		$persona = self::getBool($in);
		$capeOnClassic = self::getBool($in);
		$isPrimaryUser = self::getBool($in);
		$override = self::getBool($in);
		if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
			$trusted = strtolower(self::getString($in)) === "true";
			self::getString($in); //profile hash - TODO: expose this on SkinData
		}else{
			$trusted = true;
		}

		return new SkinData(
			$skinId,
			$skinPlayFabId,
			$skinResourcePatch,
			$skinData,
			$animations,
			$capeData,
			$geometryData,
			$geometryDataVersion,
			$animationData,
			$capeId,
			$fullSkinId,
			$armSize,
			$skinColor,
			$personaPieces,
			$pieceTintColors,
			$trusted,
			$premium,
			$persona,
			$capeOnClassic,
			$isPrimaryUser,
			$override,
		);
	}

	public static function putSkin(ByteBufferWriter $out, int $protocolId, SkinData $skin) : void{
		self::putString($out, $skin->getSkinId());
		self::putString($out, $skin->getPlayFabId());
		self::putString($out, $skin->getResourcePatch());
		self::putSkinImage($out, $skin->getSkinImage());
		if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
			VarInt::writeUnsignedInt($out, count($skin->getAnimations()));
		}else{
			LE::writeUnsignedInt($out, count($skin->getAnimations()));
		}
		foreach($skin->getAnimations() as $animation){
			self::putSkinImage($out, $animation->getImage());
			if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
				VarInt::writeUnsignedInt($out, $animation->getType());
				LE::writeFloat($out, $animation->getFrames());
				VarInt::writeUnsignedInt($out, $animation->getExpressionType());
			}else{
				LE::writeUnsignedInt($out, $animation->getType());
				LE::writeFloat($out, $animation->getFrames());
				LE::writeUnsignedInt($out, $animation->getExpressionType());
			}
		}
		self::putSkinImage($out, $skin->getCapeImage());
		self::putString($out, $skin->getGeometryData());
		self::putString($out, $skin->getGeometryDataEngineVersion());
		self::putString($out, $skin->getAnimationData());
		self::putString($out, $skin->getCapeId());
		self::putString($out, $skin->getFullSkinId());
		if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
			Byte::writeUnsigned($out, $skin->getArmSize() === SkinData::ARM_SIZE_SLIM ? 0 : 1);
			self::putBEARGB($out, self::hexColorToArgb($skin->getSkinColor()));
			VarInt::writeUnsignedInt($out, count($skin->getPersonaPieces()));
		}else{
			self::putString($out, $skin->getArmSize());
			self::putString($out, $skin->getSkinColor());
			LE::writeUnsignedInt($out, count($skin->getPersonaPieces()));
		}
		foreach($skin->getPersonaPieces() as $piece){
			self::putString($out, $piece->getPieceId());
			if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
				LE::writeUnsignedInt($out, self::PERSONA_PIECE_TYPE_IDS[$piece->getPieceType()] ?? self::PERSONA_PIECE_TYPE_UNSUPPORTED);
				try{
					$packId = Uuid::fromString($piece->getPackId());
				}catch(\InvalidArgumentException){
					$packId = Uuid::fromString(Uuid::NIL);
				}
				self::putUUID($out, $packId);
			}else{
				self::putString($out, $piece->getPieceType());
				self::putString($out, $piece->getPackId());
			}
			self::putBool($out, $piece->isDefaultPiece());
			self::putString($out, $piece->getProductId());
		}
		if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
			VarInt::writeUnsignedInt($out, count($skin->getPieceTintColors()));
		}else{
			LE::writeUnsignedInt($out, count($skin->getPieceTintColors()));
		}
		foreach($skin->getPieceTintColors() as $tint){
			if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
				self::putString($out, self::tintPieceWireType($tint->getPieceType()));
				$colors = array_slice($tint->getColors(), 0, 4);
				while(count($colors) < 4){
					$colors[] = "#0";
				}
				foreach($colors as $color){
					self::putBEARGB($out, self::hexColorToArgb($color));
				}
			}else{
				self::putString($out, $tint->getPieceType());
				LE::writeUnsignedInt($out, count($tint->getColors()));
				foreach($tint->getColors() as $color){
					self::putString($out, $color);
				}
			}
		}
		self::putBool($out, $skin->isPremium());
		self::putBool($out, $skin->isPersona());
		self::putBool($out, $skin->isPersonaCapeOnClassic());
		self::putBool($out, $skin->isPrimaryUser());
		self::putBool($out, $skin->isOverride());
		if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
			self::putString($out, $skin->isVerified() ? "true" : "false");
			self::putString($out, ""); //profile hash - TODO: expose this on SkinData
		}
	}

	/** @throws DataDecodeException */
	private static function getSkinImage(ByteBufferReader $in) : SkinImage{
		$width = LE::readUnsignedInt($in);
		$height = LE::readUnsignedInt($in);
		$data = self::getString($in);
		try{
			return new SkinImage($height, $width, $data);
		}catch(\InvalidArgumentException $e){
			throw new PacketDecodeException($e->getMessage(), 0, $e);
		}
	}

	private static function putSkinImage(ByteBufferWriter $out, SkinImage $image) : void{
		LE::writeUnsignedInt($out, $image->getWidth());
		LE::writeUnsignedInt($out, $image->getHeight());
		self::putString($out, $image->getData());
	}

	/**
	 * @return int[]
	 * @phpstan-return array{0: int, 1: int, 2: int}
	 * @throws DataDecodeException
	 */
	private static function getItemStackHeader(ByteBufferReader $in) : array{
		$id = VarInt::readSignedInt($in);
		if($id === 0){
			return [0, 0, 0];
		}

		$count = LE::readUnsignedShort($in);
		$meta = VarInt::readUnsignedInt($in);

		return [$id, $count, $meta];
	}

	private static function putItemStackHeader(ByteBufferWriter $out, ItemStack $itemStack) : bool{
		if($itemStack->getId() === 0){
			VarInt::writeSignedInt($out, 0);
			return false;
		}

		VarInt::writeSignedInt($out, $itemStack->getId());
		LE::writeUnsignedShort($out, $itemStack->getCount());
		VarInt::writeUnsignedInt($out, $itemStack->getMeta());

		return true;
	}

	/** @throws DataDecodeException */
	private static function getItemStackFooter(ByteBufferReader $in, int $id, int $meta, int $count) : ItemStack{
		$blockRuntimeId = VarInt::readSignedInt($in);
		$rawExtraData = self::getString($in);

		return new ItemStack($id, $meta, $count, $blockRuntimeId, $rawExtraData);
	}

	private static function putItemStackFooter(ByteBufferWriter $out, ItemStack $itemStack) : void{
		VarInt::writeSignedInt($out, $itemStack->getBlockRuntimeId());
		self::putString($out, $itemStack->getRawExtraData());
	}

	/**
	 * @throws PacketDecodeException
	 * @throws DataDecodeException
	 */
	public static function getItemStackWithoutStackId(ByteBufferReader $in, int $protocolId) : ItemStack{
		if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
			//1.26.40 removed the air (id 0) shortcut - the full structure is always present
			$id = VarInt::readSignedInt($in);
			$count = LE::readUnsignedShort($in);
			$meta = VarInt::readUnsignedInt($in);

			return self::getItemStackFooter($in, $id, $meta, $count);
		}
		[$id, $count, $meta] = self::getItemStackHeader($in);

		return $id !== 0 ? self::getItemStackFooter($in, $id, $meta, $count) : ItemStack::null();

	}

	public static function putItemStackWithoutStackId(ByteBufferWriter $out, int $protocolId, ItemStack $itemStack) : void{
		if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
			VarInt::writeSignedInt($out, $itemStack->getId());
			LE::writeUnsignedShort($out, $itemStack->getCount());
			VarInt::writeUnsignedInt($out, $itemStack->getMeta());
			self::putItemStackFooter($out, $itemStack);
			return;
		}
		if(self::putItemStackHeader($out, $itemStack)){
			self::putItemStackFooter($out, $itemStack);
		}
	}

	/** @throws DataDecodeException */
	public static function getItemStackWrapper(ByteBufferReader $in) : ItemStackWrapper{
		[$id, $count, $meta] = self::getItemStackHeader($in);
		if($id === 0){
			return new ItemStackWrapper(0, ItemStack::null());
		}

		$hasNetId = self::getBool($in);
		$stackId = $hasNetId ? self::readServerItemStackId($in) : 0;

		$itemStack = self::getItemStackFooter($in, $id, $meta, $count);

		return new ItemStackWrapper($stackId, $itemStack);
	}

	public static function putItemStackWrapper(ByteBufferWriter $out, ItemStackWrapper $itemStackWrapper) : void{
		$itemStack = $itemStackWrapper->getItemStack();
		if(self::putItemStackHeader($out, $itemStack)){
			$hasNetId = $itemStackWrapper->getStackId() !== 0;
			self::putBool($out, $hasNetId);
			if($hasNetId){
				self::writeServerItemStackId($out, $itemStackWrapper->getStackId());
			}

			self::putItemStackFooter($out, $itemStack);
		}
	}

	public static function getNetworkItemStackDescriptor(ByteBufferReader $in, int $protocolId) : ItemStackWrapper{
		$id = LE::readSignedShort($in);
		$count = LE::readUnsignedShort($in);
		$meta = VarInt::readUnsignedInt($in);

		$hasNetId = self::getBool($in);
		if($hasNetId){
			//1.26.40 removed the stack-ID variant
			$variant = $protocolId >= ProtocolInfo::PROTOCOL_1_26_40 ? 0 : VarInt::readUnsignedInt($in);
			$stackId = VarInt::readSignedInt($in);
		}else{
			$variant = 0;
			$stackId = 0;
		}

		$blockRuntimeId = $protocolId >= ProtocolInfo::PROTOCOL_1_26_40 ? self::getBlockRuntimeId($in) : VarInt::readUnsignedInt($in);
		$rawExtraData = self::getString($in);

		return new ItemStackWrapper($stackId, new ItemStack($id, $meta, $count, $blockRuntimeId, $rawExtraData), $variant);
	}

	public static function putNetworkItemStackDescriptor(ByteBufferWriter $out, int $protocolId, ItemStackWrapper $itemStackWrapper) : void{
		LE::writeSignedShort($out, $itemStackWrapper->getItemStack()->getId());
		LE::writeUnsignedShort($out, $itemStackWrapper->getItemStack()->getCount());
		VarInt::writeUnsignedInt($out, $itemStackWrapper->getItemStack()->getMeta());

		self::putBool($out, $hasNetId = $itemStackWrapper->getStackId() !== 0);
		if($hasNetId){
			if($protocolId < ProtocolInfo::PROTOCOL_1_26_40){
				VarInt::writeUnsignedInt($out, $itemStackWrapper->getStackIdVariant());
			}
			VarInt::writeSignedInt($out, $itemStackWrapper->getStackId());
		}

		if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
			self::putBlockRuntimeId($out, $itemStackWrapper->getItemStack()->getBlockRuntimeId());
		}else{
			VarInt::writeUnsignedInt($out, $itemStackWrapper->getItemStack()->getBlockRuntimeId());
		}
		self::putString($out, $itemStackWrapper->getItemStack()->getRawExtraData());
	}

	/**
	 * @throws PacketDecodeException
	 * @throws DataDecodeException
	 */
	public static function getRecipeIngredient(ByteBufferReader $in, int $protocolId) : RecipeIngredient{
		if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
			//1.26.40+ descriptors are name-based
			$variant = VarInt::readUnsignedInt($in);
			if($variant === 0){
				VarInt::readSignedInt($in); //aux - always 32767
				$descriptor = null;
			}elseif($variant === 1){
				$typeName = self::getString($in);
				$descriptor = match($typeName){
					"name" => new StringIdMetaItemDescriptor(self::getString($in), VarInt::readSignedInt($in)),
					"molang" => new MolangItemDescriptor(self::getString($in), LE::readSignedShort($in)),
					"item_tag" => new TagItemDescriptor(self::getString($in)),
					default => throw new PacketDecodeException("Unknown item descriptor type \"$typeName\"")
				};
				if($typeName === "item_tag"){
					VarInt::readSignedInt($in); //aux - always 32767
				}
			}else{
				throw new PacketDecodeException("Unknown item descriptor variant $variant");
			}
		}else{
			$descriptorType = Byte::readUnsigned($in);
			$descriptor = match($descriptorType){
				ItemDescriptorType::INT_ID_META => IntIdMetaItemDescriptor::read($in),
				ItemDescriptorType::STRING_ID_META => StringIdMetaItemDescriptor::read($in),
				ItemDescriptorType::TAG => TagItemDescriptor::read($in),
				ItemDescriptorType::MOLANG => MolangItemDescriptor::read($in),
				ItemDescriptorType::COMPLEX_ALIAS => ComplexAliasItemDescriptor::read($in),
				default => null
			};
		}
		$count = VarInt::readSignedInt($in);

		return new RecipeIngredient($descriptor, $count);
	}

	public static function putRecipeIngredient(ByteBufferWriter $out, int $protocolId, RecipeIngredient $ingredient) : void{
		$type = $ingredient->getDescriptor();

		if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
			if($type === null){
				VarInt::writeUnsignedInt($out, 0);
				VarInt::writeSignedInt($out, 32767);
			}else{
				VarInt::writeUnsignedInt($out, 1);
				if($type instanceof StringIdMetaItemDescriptor){
					self::putString($out, "name");
					self::putString($out, $type->getId());
					VarInt::writeSignedInt($out, $type->getMeta());
				}elseif($type instanceof MolangItemDescriptor){
					self::putString($out, "molang");
					self::putString($out, $type->getMolangExpression());
					LE::writeSignedShort($out, $type->getMolangVersion());
				}elseif($type instanceof TagItemDescriptor){
					self::putString($out, "item_tag");
					self::putString($out, $type->getTag());
					VarInt::writeSignedInt($out, 32767);
				}else{
					throw new \InvalidArgumentException("Descriptor type " . get_class($type) . " cannot be sent to 1.26.40+ clients (descriptors are name-based)");
				}
			}
		}else{
			Byte::writeUnsigned($out, $type?->getTypeId() ?? 0);
			$type?->write($out);
		}

		VarInt::writeSignedInt($out, $ingredient->getCount());
	}

	/**
	 * 1.26.40+ item stack request ingredient format (variant + legacy type byte).
	 *
	 * @throws PacketDecodeException
	 * @throws DataDecodeException
	 */
	public static function getStackRequestIngredient(ByteBufferReader $in) : RecipeIngredient{
		$variant = VarInt::readUnsignedInt($in);
		$legacy = Byte::readUnsigned($in);
		if($variant !== $legacy){
			throw new PacketDecodeException("Stack request descriptor variant $variant does not match legacy type $legacy");
		}
		$descriptor = match($variant){
			0 => null,
			1 => new StringIdMetaItemDescriptor(self::getString($in), VarInt::readSignedInt($in)),
			2 => new MolangItemDescriptor(self::getString($in), LE::readSignedShort($in)),
			3 => new TagItemDescriptor(self::getString($in)),
			default => throw new PacketDecodeException("Unknown stack request descriptor variant $variant")
		};
		$count = LE::readUnsignedShort($in);

		return new RecipeIngredient($descriptor, $count);
	}

	public static function putStackRequestIngredient(ByteBufferWriter $out, RecipeIngredient $ingredient) : void{
		$type = $ingredient->getDescriptor();
		[$variant, $payloadWriter] = match(true){
			$type === null => [0, null],
			$type instanceof StringIdMetaItemDescriptor => [1, function(ByteBufferWriter $out) use ($type) : void{
				self::putString($out, $type->getId());
				VarInt::writeSignedInt($out, $type->getMeta());
			}],
			$type instanceof MolangItemDescriptor => [2, function(ByteBufferWriter $out) use ($type) : void{
				self::putString($out, $type->getMolangExpression());
				LE::writeSignedShort($out, $type->getMolangVersion());
			}],
			$type instanceof TagItemDescriptor => [3, function(ByteBufferWriter $out) use ($type) : void{
				self::putString($out, $type->getTag());
			}],
			default => throw new \InvalidArgumentException("Descriptor type " . get_class($type) . " cannot be sent in a stack request")
		};
		VarInt::writeUnsignedInt($out, $variant);
		Byte::writeUnsigned($out, $variant);
		if($payloadWriter !== null){
			$payloadWriter($out);
		}
		LE::writeUnsignedShort($out, $ingredient->getCount());
	}

	/**
	 * Decodes entity metadata from the stream.
	 *
	 * @return MetadataProperty[]
	 * @phpstan-return array<int, MetadataProperty>
	 *
	 * @throws PacketDecodeException
	 * @throws DataDecodeException
	 */
	public static function getEntityMetadata(ByteBufferReader $in, int $protocolId) : array{
		$count = VarInt::readUnsignedInt($in);
		$data = [];
		for($i = 0; $i < $count; ++$i){
			$key = VarInt::readUnsignedInt($in);
			$type = VarInt::readUnsignedInt($in);

			if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
				$legacyType = Byte::readUnsigned($in);
				if($legacyType !== $type){
					throw new PacketDecodeException("Legacy metadata type $legacyType does not match tagged-variant type $type");
				}
			}

			$data[$key] = self::readMetadataProperty($in, $type);
		}

		return $data;
	}

	/** @throws DataDecodeException */
	private static function readMetadataProperty(ByteBufferReader $in, int $type) : MetadataProperty{
		return match($type){
			ByteMetadataProperty::ID => ByteMetadataProperty::read($in),
			ShortMetadataProperty::ID => ShortMetadataProperty::read($in),
			IntMetadataProperty::ID => IntMetadataProperty::read($in),
			FloatMetadataProperty::ID => FloatMetadataProperty::read($in),
			StringMetadataProperty::ID => StringMetadataProperty::read($in),
			CompoundTagMetadataProperty::ID => CompoundTagMetadataProperty::read($in),
			BlockPosMetadataProperty::ID => BlockPosMetadataProperty::read($in),
			LongMetadataProperty::ID => LongMetadataProperty::read($in),
			Vec3MetadataProperty::ID => Vec3MetadataProperty::read($in),
			default => throw new PacketDecodeException("Unknown entity metadata type " . $type),
		};
	}

	/**
	 * Writes entity metadata to the packet buffer.
	 *
	 * @param MetadataProperty[] $metadata
	 *
	 * @phpstan-param array<int, MetadataProperty> $metadata
	 */
	public static function putEntityMetadata(ByteBufferWriter $out, int $protocolId, array $metadata) : void{
		VarInt::writeUnsignedInt($out, count($metadata));
		foreach($metadata as $key => $d){
			VarInt::writeUnsignedInt($out, $key);
			VarInt::writeUnsignedInt($out, $d->getTypeId());
			if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
				Byte::writeUnsigned($out, $d->getTypeId());
			}
			$d->write($out);
		}
	}

	/** @throws DataDecodeException */
	public static function getActorUniqueId(ByteBufferReader $in) : int{
		return VarInt::readSignedLong($in);
	}

	public static function putActorUniqueId(ByteBufferWriter $out, int $eid) : void{
		VarInt::writeSignedLong($out, $eid);
	}

	/** @throws DataDecodeException */
	public static function getActorRuntimeId(ByteBufferReader $in) : int{
		return VarInt::readUnsignedLong($in);
	}

	public static function putActorRuntimeId(ByteBufferWriter $out, int $eid) : void{
		VarInt::writeUnsignedLong($out, $eid);
	}

	/**
	 * Reads a block position
	 *
	 * @throws DataDecodeException
	 */
	public static function getBlockPosition(ByteBufferReader $in, bool $signedY = true) : BlockPosition{
		$x = VarInt::readSignedInt($in);
		$y = $signedY ? VarInt::readSignedInt($in) : Binary::signInt(VarInt::readUnsignedInt($in));
		$z = VarInt::readSignedInt($in);
		return new BlockPosition($x, $y, $z);
	}

	/**
	 * Writes a block position
	 */
	public static function putBlockPosition(ByteBufferWriter $out, BlockPosition $blockPosition, bool $signedY = true) : void{
		VarInt::writeSignedInt($out, $blockPosition->getX());
		if($signedY){
			VarInt::writeSignedInt($out, $blockPosition->getY());
		}else{
			VarInt::writeUnsignedInt($out, Binary::unsignInt($blockPosition->getY()));
		}
		VarInt::writeSignedInt($out, $blockPosition->getZ());
	}

	/**
	 * Reads a floating-point Vector3 object with coordinates rounded to 4 decimal places.
	 *
	 * @throws DataDecodeException
	 */
	public static function getVector3(ByteBufferReader $in) : Vector3{
		$x = LE::readFloat($in);
		$y = LE::readFloat($in);
		$z = LE::readFloat($in);
		return new Vector3($x, $y, $z);
	}

	/**
	 * Reads a floating-point Vector2 object with coordinates rounded to 4 decimal places.
	 *
	 * @throws DataDecodeException
	 */
	public static function getVector2(ByteBufferReader $in) : Vector2{
		$x = LE::readFloat($in);
		$y = LE::readFloat($in);
		return new Vector2($x, $y);
	}

	/**
	 * Writes a floating-point Vector3 object, or 3x zero if null is given.
	 *
	 * Note: ONLY use this where it is reasonable to allow not specifying the vector.
	 * For all other purposes, use the non-nullable version.
	 *
	 * @see CommonTypes::putVector3()
	 */
	public static function putVector3Nullable(ByteBufferWriter $out, ?Vector3 $vector) : void{
		if($vector !== null){
			self::putVector3($out, $vector);
		}else{
			LE::writeFloat($out, 0.0);
			LE::writeFloat($out, 0.0);
			LE::writeFloat($out, 0.0);
		}
	}

	/**
	 * Writes a floating-point Vector3 object
	 */
	public static function putVector3(ByteBufferWriter $out, Vector3 $vector) : void{
		LE::writeFloat($out, $vector->x);
		LE::writeFloat($out, $vector->y);
		LE::writeFloat($out, $vector->z);
	}

	/**
	 * Writes a floating-point Vector2 object
	 */
	public static function putVector2(ByteBufferWriter $out, Vector2 $vector2) : void{
		LE::writeFloat($out, $vector2->x);
		LE::writeFloat($out, $vector2->y);
	}

	/** @throws DataDecodeException */
	public static function getRotationByte(ByteBufferReader $in) : float{
		return Byte::readUnsigned($in) * (360 / 256);
	}

	public static function putRotationByte(ByteBufferWriter $out, float $rotation) : void{
		Byte::writeUnsigned($out, (int) ($rotation / (360 / 256)));
	}

	/** @throws DataDecodeException */
	private static function readGameRule(ByteBufferReader $in, int $protocolId, int $type, bool $isPlayerModifiable, bool $isStartGame) : GameRule{
		if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40 && $type === NullGameRule::ID){
			return NullGameRule::decode($in, $protocolId, $isPlayerModifiable);
		}
		return match($type){
			BoolGameRule::ID => BoolGameRule::decode($in, $protocolId, $isPlayerModifiable),
			IntGameRule::ID => IntGameRule::decode($in, $protocolId, $isPlayerModifiable, $isStartGame),
			FloatGameRule::ID => FloatGameRule::decode($in, $protocolId, $isPlayerModifiable),
			default => throw new PacketDecodeException("Unknown gamerule type $type"),
		};
	}

	/**
	 * Reads gamerules
	 *
	 * @return GameRule[] game rule name => value
	 * @phpstan-return array<string, GameRule>
	 *
	 * @throws PacketDecodeException
	 * @throws DataDecodeException
	 */
	public static function getGameRules(ByteBufferReader $in, int $protocolId, bool $isStartGame) : array{
		$count = VarInt::readUnsignedInt($in);
		$rules = [];
		for($i = 0; $i < $count; ++$i){
			$name = self::getString($in);
			$isPlayerModifiable = self::getBool($in);
			$type = VarInt::readUnsignedInt($in);
			$rules[$name] = self::readGameRule($in, $protocolId, $type, $isPlayerModifiable, $isStartGame);
		}

		return $rules;
	}

	/**
	 * Writes a gamerule array
	 *
	 * @param GameRule[] $rules
	 * @phpstan-param array<string, GameRule> $rules
	 */
	public static function putGameRules(ByteBufferWriter $out, int $protocolId, array $rules, bool $isStartGame) : void{
		VarInt::writeUnsignedInt($out, count($rules));
		foreach($rules as $name => $rule){
			self::putString($out, $name);
			self::putBool($out, $rule->isPlayerModifiable());
			VarInt::writeUnsignedInt($out, $rule->getTypeId());
			$rule->encode($out, $protocolId, $isStartGame);
		}
	}

	/** @throws DataDecodeException */
	public static function getEntityLink(ByteBufferReader $in, int $protocolId) : EntityLink{
		$fromActorUniqueId = self::getActorUniqueId($in);
		$toActorUniqueId = self::getActorUniqueId($in);
		$type = Byte::readUnsigned($in);
		$immediate = self::getBool($in);
		$causedByRider = self::getBool($in);
		if($protocolId >= ProtocolInfo::PROTOCOL_1_21_20){
			$vehicleAngularVelocity = LE::readFloat($in);
		}
		return new EntityLink($fromActorUniqueId, $toActorUniqueId, $type, $immediate, $causedByRider, $vehicleAngularVelocity ?? 0);
	}

	public static function putEntityLink(ByteBufferWriter $out, int $protocolId, EntityLink $link) : void{
		self::putActorUniqueId($out, $link->fromActorUniqueId);
		self::putActorUniqueId($out, $link->toActorUniqueId);
		Byte::writeUnsigned($out, $link->type);
		self::putBool($out, $link->immediate);
		self::putBool($out, $link->causedByRider);
		if($protocolId >= ProtocolInfo::PROTOCOL_1_21_20){
			LE::writeFloat($out, $link->vehicleAngularVelocity);
		}
	}

	/** @throws DataDecodeException */
	public static function getCommandOriginData(ByteBufferReader $in, int $protocolId) : CommandOriginData{
		$result = new CommandOriginData();

		$result->type = $protocolId >= ProtocolInfo::PROTOCOL_1_21_130 ? CommonTypes::getString($in) : CommandOriginData::getTypeFromId(VarInt::readUnsignedInt($in));
		$result->uuid = self::getUUID($in);
		$result->requestId = self::getString($in);
		if($protocolId >= ProtocolInfo::PROTOCOL_1_21_130){
			$result->playerActorUniqueId = LE::readSignedLong($in);
		}elseif($result->type === CommandOriginData::ORIGIN_DEV_CONSOLE or $result->type === CommandOriginData::ORIGIN_TEST){
			$result->playerActorUniqueId = VarInt::readSignedLong($in);
		}

		return $result;
	}

	public static function putCommandOriginData(ByteBufferWriter $out, CommandOriginData $data, int $protocolId) : void{
		if($protocolId >= ProtocolInfo::PROTOCOL_1_21_130){
			self::putString($out, $data->type);
		}else{
			VarInt::writeUnsignedInt($out, CommandOriginData::getIdFromType($data->type));
		}
		self::putUUID($out, $data->uuid);
		self::putString($out, $data->requestId);
		if($protocolId >= ProtocolInfo::PROTOCOL_1_21_130){
			LE::writeSignedLong($out, $data->playerActorUniqueId);
		}elseif($data->type === CommandOriginData::ORIGIN_DEV_CONSOLE or $data->type === CommandOriginData::ORIGIN_TEST){
			VarInt::writeSignedLong($out, $data->playerActorUniqueId);
		}
	}

	/** @throws DataDecodeException */
	public static function getStructureSettings(ByteBufferReader $in, int $protocolId) : StructureSettings{
		$result = new StructureSettings();

		$result->paletteName = self::getString($in);

		$result->ignoreEntities = self::getBool($in);
		$result->ignoreBlocks = self::getBool($in);
		$result->allowNonTickingChunks = self::getBool($in);

		$result->dimensions = self::getBlockPosition($in, $protocolId >= ProtocolInfo::PROTOCOL_1_26_10);
		$result->offset = self::getBlockPosition($in, $protocolId >= ProtocolInfo::PROTOCOL_1_26_10);

		$result->lastTouchedByPlayerID = self::getActorUniqueId($in);
		$result->rotation = Byte::readUnsigned($in);
		$result->mirror = Byte::readUnsigned($in);
		$result->animationMode = Byte::readUnsigned($in);
		$result->animationSeconds = LE::readFloat($in);
		$result->integrityValue = LE::readFloat($in);
		$result->integritySeed = LE::readUnsignedInt($in);
		$result->pivot = self::getVector3($in);

		return $result;
	}

	public static function putStructureSettings(ByteBufferWriter $out, StructureSettings $structureSettings, int $protocolId) : void{
		self::putString($out, $structureSettings->paletteName);

		self::putBool($out, $structureSettings->ignoreEntities);
		self::putBool($out, $structureSettings->ignoreBlocks);
		self::putBool($out, $structureSettings->allowNonTickingChunks);

		self::putBlockPosition($out, $structureSettings->dimensions, $protocolId >= ProtocolInfo::PROTOCOL_1_26_10);
		self::putBlockPosition($out, $structureSettings->offset, $protocolId >= ProtocolInfo::PROTOCOL_1_26_10);

		self::putActorUniqueId($out, $structureSettings->lastTouchedByPlayerID);
		Byte::writeUnsigned($out, $structureSettings->rotation);
		Byte::writeUnsigned($out, $structureSettings->mirror);
		Byte::writeUnsigned($out, $structureSettings->animationMode);
		LE::writeFloat($out, $structureSettings->animationSeconds);
		LE::writeFloat($out, $structureSettings->integrityValue);
		LE::writeUnsignedInt($out, $structureSettings->integritySeed);
		self::putVector3($out, $structureSettings->pivot);
	}

	/** @throws DataDecodeException */
	public static function getStructureEditorData(ByteBufferReader $in, int $protocolId) : StructureEditorData{
		$result = new StructureEditorData();

		$result->structureName = self::getString($in);
		if($protocolId >= ProtocolInfo::PROTOCOL_1_21_60){
			$result->filteredStructureName = self::getString($in);
		}
		$result->structureDataField = self::getString($in);

		$result->includePlayers = self::getBool($in);
		$result->showBoundingBox = self::getBool($in);

		$result->structureBlockType = VarInt::readSignedInt($in);
		$result->structureSettings = self::getStructureSettings($in, $protocolId);
		$result->structureRedstoneSaveMode = VarInt::readSignedInt($in);

		return $result;
	}

	public static function putStructureEditorData(ByteBufferWriter $out, int $protocolId, StructureEditorData $structureEditorData) : void{
		self::putString($out, $structureEditorData->structureName);
		if($protocolId >= ProtocolInfo::PROTOCOL_1_21_60){
			self::putString($out, $structureEditorData->filteredStructureName);
		}
		self::putString($out, $structureEditorData->structureDataField);

		self::putBool($out, $structureEditorData->includePlayers);
		self::putBool($out, $structureEditorData->showBoundingBox);

		VarInt::writeSignedInt($out, $structureEditorData->structureBlockType);
		self::putStructureSettings($out, $structureEditorData->structureSettings, $protocolId);
		VarInt::writeSignedInt($out, $structureEditorData->structureRedstoneSaveMode);
	}

	/** @throws PacketDecodeException */
	public static function getNbtRoot(ByteBufferReader $in) : TreeRoot{
		$offset = $in->getOffset();
		try{
			return (new NetworkNbtSerializer())->read($in->getData(), $offset, 512);
		}catch(NbtDataException $e){
			throw PacketDecodeException::wrap($e, "Failed decoding NBT root");
		}finally{
			$in->setOffset($offset);
		}
	}

	public static function getNbtCompoundRoot(ByteBufferReader $in) : CompoundTag{
		try{
			return self::getNbtRoot($in)->mustGetCompoundTag();
		}catch(NbtDataException $e){
			throw PacketDecodeException::wrap($e, "Expected TAG_Compound NBT root");
		}
	}

	/** @throws DataDecodeException */
	public static function readRecipeNetId(ByteBufferReader $in) : int{
		return VarInt::readUnsignedInt($in);
	}

	public static function writeRecipeNetId(ByteBufferWriter $out, int $id) : void{
		VarInt::writeUnsignedInt($out, $id);
	}

	/** @throws DataDecodeException */
	public static function readCreativeItemNetId(ByteBufferReader $in) : int{
		return VarInt::readUnsignedInt($in);
	}

	public static function writeCreativeItemNetId(ByteBufferWriter $out, int $id) : void{
		VarInt::writeUnsignedInt($out, $id);
	}

	/**
	 * This is a union of ItemStackRequestId, LegacyItemStackRequestId, and ServerItemStackId, used in serverbound
	 * packets to allow the client to refer to server known items, or items which may have been modified by a previous
	 * as-yet unacknowledged request from the client.
	 *
	 * - Server itemstack ID is positive
	 * - InventoryTransaction "legacy" request ID is negative and even
	 * - ItemStackRequest request ID is negative and odd
	 * - 0 refers to an empty itemstack (air)
	 *
	 * @throws DataDecodeException
	 */
	public static function readItemStackNetIdVariant(ByteBufferReader $in) : int{
		return VarInt::readSignedInt($in);
	}

	/**
	 * This is a union of ItemStackRequestId, LegacyItemStackRequestId, and ServerItemStackId, used in serverbound
	 * packets to allow the client to refer to server known items, or items which may have been modified by a previous
	 * as-yet unacknowledged request from the client.
	 */
	public static function writeItemStackNetIdVariant(ByteBufferWriter $out, int $id) : void{
		VarInt::writeSignedInt($out, $id);
	}

	/** @throws DataDecodeException */
	public static function readItemStackRequestId(ByteBufferReader $in) : int{
		return VarInt::readSignedInt($in);
	}

	public static function writeItemStackRequestId(ByteBufferWriter $out, int $id) : void{
		VarInt::writeSignedInt($out, $id);
	}

	/** @throws DataDecodeException */
	public static function readLegacyItemStackRequestId(ByteBufferReader $in) : int{
		return VarInt::readSignedInt($in);
	}

	public static function writeLegacyItemStackRequestId(ByteBufferWriter $out, int $id) : void{
		VarInt::writeSignedInt($out, $id);
	}

	/** @throws DataDecodeException */
	public static function readServerItemStackId(ByteBufferReader $in) : int{
		return VarInt::readSignedInt($in);
	}

	public static function writeServerItemStackId(ByteBufferWriter $out, int $id) : void{
		VarInt::writeSignedInt($out, $id);
	}

	/**
	 * @phpstan-template T
	 * @phpstan-param \Closure(ByteBufferReader) : (T|null) $reader
	 * @phpstan-return T|null
	 * @throws DataDecodeException
	 */
	public static function readOptional(ByteBufferReader $in, \Closure $reader) : mixed{
		if(self::getBool($in)){
			return $reader($in);
		}
		return null;
	}

	/**
	 * @phpstan-template T
	 * @phpstan-param T|null $value
	 * @phpstan-param \Closure(ByteBufferWriter, T) : void $writer
	 */
	public static function writeOptional(ByteBufferWriter $out, mixed $value, \Closure $writer) : void{
		if($value !== null){
			self::putBool($out, true);
			$writer($out, $value);
		}else{
			self::putBool($out, false);
		}
	}
}
