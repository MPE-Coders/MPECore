<?php
declare(strict_types=1);
namespace mpe\network\mcpe;
use pocketmine\network\mcpe\protocol as P;
use pocketmine\network\mcpe\protocol\types\command as C;
use pocketmine\network\mcpe\protocol\serializer\AvailableCommandsPacketAssembler;
final class CommandNetwork {
    public static function packet(): P\AvailableCommandsPacket {
        $commands=[];
        foreach(['help'=>'Show MPE help','version'=>'Show server/client version','pos'=>'Position','blocks'=>'Supported blocks','block'=>'Put a building block in your hand','spawn'=>'Return to safe spawn','mpe'=>'MPE diagnostics','setblock'=>'Operator block edit','hello'=>'Example PHP plugin','whereami'=>'Example PHP position command'] as $name=>$description) {
            $parameters=in_array($name,['block','mpe','setblock'],true)?[C\CommandParameter::standard('arguments',P\AvailableCommandsPacket::ARG_TYPE_RAWTEXT,0,true)]:[];
            $commands[]=new C\CommandData($name,$description,0,0,null,[new C\CommandOverload(false,$parameters)],[]);
        }
        return AvailableCommandsPacketAssembler::assemble($commands,[],[]);
    }
}
