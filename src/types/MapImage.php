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
use pmmp\encoding\DataDecodeException;
use pmmp\encoding\LE;
use pmmp\encoding\VarInt;
use pocketmine\color\Color;
use pocketmine\network\mcpe\protocol\PacketDecodeException;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\utils\Binary;
use function count;

final class MapImage{
	//these limits are enforced in the protocol in 1.20.0
	public const MAX_HEIGHT = 128;
	public const MAX_WIDTH = 128;

	private int $width;
	private int $height;
	/**
	 * @var Color[][]
	 * @phpstan-var list<list<Color>>
	 */
	private array $pixels;
	/**
	 * @var string[] encoded pixel data, indexed by wire format (0 = pre-1.26.40 varint RGBA, 1 = 1.26.40+ LE ARGB)
	 * @phpstan-var array<int, string>
	 */
	private array $encodedPixelCache = [];

	/**
	 * @param Color[][] $pixels
	 * @phpstan-param list<list<Color>> $pixels
	 */
	public function __construct(array $pixels){
		$rowLength = null;
		foreach($pixels as $row){
			if($rowLength === null){
				$rowLength = count($row);
			}elseif(count($row) !== $rowLength){
				throw new \InvalidArgumentException("All rows must have the same number of pixels");
			}
		}
		if($rowLength === null){
			throw new \InvalidArgumentException("No pixels provided");
		}
		if($rowLength > self::MAX_WIDTH){
			throw new \InvalidArgumentException("Image width must be at most " . self::MAX_WIDTH . " pixels wide");
		}
		if(count($pixels) > self::MAX_HEIGHT){
			throw new \InvalidArgumentException("Image height must be at most " . self::MAX_HEIGHT . " pixels tall");
		}
		$this->height = count($pixels);
		$this->width = $rowLength;
		$this->pixels = $pixels;
	}

	public function getWidth() : int{ return $this->width; }

	public function getHeight() : int{ return $this->height; }

	/**
	 * @return Color[][]
	 * @phpstan-return list<list<Color>>
	 */
	public function getPixels() : array{ return $this->pixels; }

	public function encode(ByteBufferWriter $out, int $protocolId) : void{
		$cacheKey = $protocolId >= ProtocolInfo::PROTOCOL_1_26_40 ? 1 : 0;
		if(!isset($this->encodedPixelCache[$cacheKey])){
			$serializer = new ByteBufferWriter();
			for($y = 0; $y < $this->height; ++$y){
				for($x = 0; $x < $this->width; ++$x){
					if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
						LE::writeUnsignedInt($serializer, $this->pixels[$y][$x]->toARGB());
					}else{
						//if mojang had any sense this would just be a regular LE int
						VarInt::writeUnsignedInt($serializer, Binary::flipIntEndianness($this->pixels[$y][$x]->toRGBA()));
					}
				}
			}
			$this->encodedPixelCache[$cacheKey] = $serializer->getData();
		}

		$out->writeByteArray($this->encodedPixelCache[$cacheKey]);
	}

	/**
	 * @throws PacketDecodeException
	 * @throws DataDecodeException
	 */
	public static function decode(ByteBufferReader $in, int $height, int $width, int $protocolId) : self{
		if($width > self::MAX_WIDTH){
			throw new PacketDecodeException("Image width must be at most " . self::MAX_WIDTH . " pixels wide");
		}
		if($height > self::MAX_HEIGHT){
			throw new PacketDecodeException("Image height must be at most " . self::MAX_HEIGHT . " pixels tall");
		}
		$pixels = [];

		for($y = 0; $y < $height; ++$y){
			$row = [];
			for($x = 0; $x < $width; ++$x){
				if($protocolId >= ProtocolInfo::PROTOCOL_1_26_40){
					$row[] = Color::fromARGB(LE::readUnsignedInt($in));
				}else{
					$row[] = Color::fromRGBA(Binary::flipIntEndianness(VarInt::readUnsignedInt($in)));
				}
			}
			$pixels[] = $row;
		}

		return new self($pixels);
	}
}
