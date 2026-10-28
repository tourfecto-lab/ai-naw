<?php
/** ISO QR byte encoding, adaptive versions 1..40, level L, interleaved RS blocks, mask 0. Local generation: no third-party ticket disclosure. */
class QrCodeService
{
    public static function matrix(string $text): array
    {
        $spec=require __DIR__.'/../../Config/qr_capacity.php';$version=0;$capacity=0;
        foreach($spec['blocks'] as $v=>$layout){$cap=array_sum(array_column($layout,0));$countBits=(int)$v<10?8:16;if(strlen($text)*8+4+$countBits<=$cap*8){$version=(int)$v;$capacity=$cap;break;}}
        if(!$version)throw new InvalidArgumentException('رابط التذكرة يتجاوز سعة QR القصوى');
        $countBits=$version<10?8:16;
        $bits='0100'.str_pad(decbin(strlen($text)),$countBits,'0',STR_PAD_LEFT);
        foreach(str_split($text)as$c)$bits.=str_pad(decbin(ord($c)),8,'0',STR_PAD_LEFT);
        $bits.=str_repeat('0',min(4,$capacity*8-strlen($bits)));$bits.=str_repeat('0',(8-strlen($bits)%8)%8);
        $data=[];foreach(str_split($bits,8)as$b)$data[]=bindec($b);
        for($i=0;count($data)<$capacity;$i++)$data[]=$i%2?0x11:0xec;
        $parts=[];$ecc=[];$offset=0;
        foreach($spec['blocks'][$version] as [$dataCount,$ecCount]){
            $part=array_slice($data,$offset,$dataCount);$offset+=$dataCount;$parts[]=$part;
            $generator=[1];$root=1;for($i=0;$i<$ecCount;$i++){$next=array_fill(0,count($generator)+1,0);foreach($generator as$j=>$v){$next[$j]^=$v;$next[$j+1]^=self::mul($v,$root);}$generator=$next;$root=self::mul($root,2);}
            $remainder=array_fill(0,$ecCount,0);foreach($part as$v){$factor=$v^$remainder[0];array_shift($remainder);$remainder[]=0;for($i=0;$i<$ecCount;$i++)$remainder[$i]^=self::mul($generator[$i+1],$factor);}$ecc[]=$remainder;
        }
        $words=[];foreach([$parts,$ecc] as $group){$max=max(array_map('count',$group));for($i=0;$i<$max;$i++)foreach($group as $part)if(isset($part[$i]))$words[]=$part[$i];}
        $stream='';foreach($words as$v)$stream.=str_pad(decbin($v),8,'0',STR_PAD_LEFT);
        $n=$version*4+17;$m=array_fill(0,$n,array_fill(0,$n,false));$reserved=$m;
        $set=function($x,$y,$dark)use(&$m,&$reserved,$n){if($x>=0&&$x<$n&&$y>=0&&$y<$n){$m[$y][$x]=(bool)$dark;$reserved[$y][$x]=true;}};
        foreach([[0,0],[$n-7,0],[0,$n-7]]as[$fx,$fy])for($dy=-1;$dy<=7;$dy++)for($dx=-1;$dx<=7;$dx++)$set($fx+$dx,$fy+$dy,$dx>=0&&$dx<=6&&$dy>=0&&$dy<=6&&($dx===0||$dx===6||$dy===0||$dy===6||($dx>=2&&$dx<=4&&$dy>=2&&$dy<=4)));
        for($i=8;$i<$n-8;$i++){$set($i,6,$i%2===0);$set(6,$i,$i%2===0);}
        $centers=$spec['positions'][$version-1];
        foreach($centers as $cy)foreach($centers as $cx){if(($cx===6&&($cy===6||$cy===$n-7))||($cx===$n-7&&$cy===6))continue;for($dy=-2;$dy<=2;$dy++)for($dx=-2;$dx<=2;$dx++)$set($cx+$dx,$cy+$dy,max(abs($dx),abs($dy))!==1);}
        if($version>=7){$rem=$version<<12;for($i=17;$i>=12;$i--)if(($rem>>$i)&1)$rem^=0x1f25<<($i-12);$verBits=($version<<12)|$rem;for($i=0;$i<18;$i++){$dark=($verBits>>$i)&1;$a=$n-11+$i%3;$b=intdiv($i,3);$set($a,$b,$dark);$set($b,$a,$dark);}}
        // Level L=01, mask=000; format BCH(15,5).
        $format=8;$rem=$format<<10;for($i=14;$i>=10;$i--)if(($rem>>$i)&1)$rem^=0x537<<($i-10);$format=(($format<<10)|$rem)^0x5412;
        for($i=0;$i<=5;$i++)$set(8,$i,($format>>$i)&1);$set(8,7,($format>>6)&1);$set(8,8,($format>>7)&1);$set(7,8,($format>>8)&1);
        for($i=9;$i<15;$i++)$set(14-$i,8,($format>>$i)&1);
        for($i=0;$i<8;$i++)$set($n-1-$i,8,($format>>$i)&1);
        for($i=8;$i<15;$i++)$set(8,$n-15+$i,($format>>$i)&1);
        $set(8,$n-8,true);$index=0;$up=true;
        for($right=$n-1;$right>=1;$right-=2){if($right===6)$right=5;for($v=0;$v<$n;$v++){$y=$up?$n-1-$v:$v;for($j=0;$j<2;$j++){$x=$right-$j;if(!$reserved[$y][$x]){$bit=$index<strlen($stream)?$stream[$index]==='1':false;$index++;$m[$y][$x]=$bit^(($x+$y)%2===0);}}}$up=!$up;}
        return $m;
    }
    private static function mul(int $x,int $y): int{$z=0;for($i=0;$i<8;$i++){if($y&1)$z^=$x;$y>>=1;$x<<=1;if($x&256)$x^=0x11d;}return $z;}
    public static function svg(string $text): string
    {
        $matrix=self::matrix($text);$path='';foreach($matrix as$y=>$row)foreach($row as$x=>$dark)if($dark)$path.='M'.($x+4).' '.($y+4).'h1v1h-1z';
        $size=count($matrix)+8;
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$size.' '.$size.'" width="270" height="270" role="img" aria-label="رمز التذكرة"><rect width="'.$size.'" height="'.$size.'" fill="white"/><path d="'.$path.'" fill="black"/></svg>';
    }
}
