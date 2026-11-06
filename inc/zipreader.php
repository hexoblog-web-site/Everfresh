<?php
if (!defined('ZBP_PATH')) { exit('Access denied'); }

/**
 * 极简 ZIP 读取器
 *
 * 自动更新要在虚拟主机上也能跑，而 ZipArchive 未必装了（很多主机只给最小 PHP），
 * 所以这里自己解析：只做更新真正需要的一件事——按名字读出解压后的内容。
 *
 * 支持 stored / deflate 两种压缩方式（GitHub 打包用 deflate），不支持 ZIP64、加密、分卷。
 * 解压前请自行校验路径，本类只负责取数据。
 */
class Everfresh_Zip_Reader
{
    /** @var resource|null */
    protected $fp = null;

    protected $size = 0;

    /** @var array 名称 => array(method, csize, usize, crc, offset) */
    protected $entries = array();

    protected $error = '';

    public function __construct($file)
    {
        $this->fp = @fopen($file, 'rb');
        if (!$this->fp) {
            $this->error = '无法打开压缩包';
            return;
        }
        $stat = fstat($this->fp);
        $this->size = (int) $stat['size'];
        if ($this->size < 22) {
            $this->error = '压缩包为空或已损坏';
            return;
        }
        $this->readDirectory();
    }

    public function __destruct()
    {
        if ($this->fp) {
            @fclose($this->fp);
        }
    }

    /** 最近一次失败原因 */
    public function error()
    {
        return $this->error;
    }

    /**
     * 条目清单（不含目录）
     *
     * @return array name => array('method','csize','usize','crc','offset')
     */
    public function entries()
    {
        return $this->entries;
    }

    /**
     * 取出一个文件的解压内容
     *
     * @param string $name 压缩包内的路径，如 Everfresh-main/include.php
     *
     * @return string|false
     */
    public function read($name)
    {
        if (!isset($this->entries[$name])) {
            $this->error = '压缩包里没有该文件：' . $name;
            return false;
        }
        $e = $this->entries[$name];

        // 本地文件头里的名称/额外字段长度可能与中央目录不同，必须重新读
        if (fseek($this->fp, (int) $e['offset']) !== 0) {
            $this->error = '读取文件头失败';
            return false;
        }
        $head = fread($this->fp, 30);
        if (strlen($head) < 30) {
            $this->error = '文件头不完整';
            return false;
        }
        $h = unpack('Vsig/vneed/vflag/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnamelen/vextralen', $head);
        if ($h['sig'] !== 0x04034b50) {
            $this->error = '文件头签名不正确';
            return false;
        }

        $start = (int) $e['offset'] + 30 + (int) $h['namelen'] + (int) $h['extralen'];
        $csize = (int) $e['csize'];
        if ($csize === 0) {
            return '';
        }
        if (fseek($this->fp, $start) !== 0) {
            $this->error = '定位文件数据失败';
            return false;
        }

        $raw = '';
        $left = $csize;
        while ($left > 0) {
            $chunk = fread($this->fp, min($left, 262144));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $raw .= $chunk;
            $left -= strlen($chunk);
        }
        if (strlen($raw) !== $csize) {
            $this->error = '文件数据不完整';
            return false;
        }

        $data = $raw;
        if ((int) $e['method'] === 8) {
            $data = @gzinflate($raw);
            if ($data === false) {
                $this->error = '解压失败（deflate）';
                return false;
            }
        } elseif ((int) $e['method'] !== 0) {
            $this->error = '不支持的压缩方式：' . (int) $e['method'];
            return false;
        }

        if ((int) $e['usize'] !== strlen($data)) {
            $this->error = '解压后大小不符';
            return false;
        }
        if (sprintf('%u', crc32($data)) !== sprintf('%u', (int) $e['crc'])) {
            $this->error = 'CRC 校验失败';
            return false;
        }

        return $data;
    }

    /** 解析中央目录 */
    protected function readDirectory()
    {
        $tailLen = (int) min($this->size, 65557);
        if (fseek($this->fp, $this->size - $tailLen) !== 0) {
            $this->error = '定位目录失败';
            return;
        }
        $tail = fread($this->fp, $tailLen);
        $pos = strrpos($tail, "PK\x05\x06");
        if ($pos === false) {
            $this->error = '不是有效的 zip 文件';
            return;
        }
        $eocd = substr($tail, $pos, 22);
        if (strlen($eocd) < 22) {
            $this->error = '目录信息不完整';
            return;
        }
        $u = unpack('Vsig/vdisk/vcddisk/ventries/vtotal/Vcdsize/Vcdoffset/vcomment', $eocd);

        $total = (int) $u['total'];
        $cdOffset = (int) $u['cdoffset'];
        if ($total <= 0 || $cdOffset <= 0 || $cdOffset >= $this->size) {
            $this->error = '压缩包里没有文件';
            return;
        }

        if (fseek($this->fp, $cdOffset) !== 0) {
            $this->error = '定位中央目录失败';
            return;
        }

        for ($i = 0; $i < $total; $i++) {
            $head = fread($this->fp, 46);
            if (strlen($head) < 46) {
                break;
            }
            $h = unpack('Vsig/vmade/vneed/vflag/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnamelen/vextralen/vcommentlen/vdisk/vintattr/Vextattr/Voffset', $head);
            if ($h['sig'] !== 0x02014b50) {
                break;
            }
            $nameLen = (int) $h['namelen'];
            $name = $nameLen > 0 ? fread($this->fp, $nameLen) : '';
            if (strlen($name) !== $nameLen) {
                break;
            }
            // 跳过 extra 与 comment，直接到下一个目录记录
            $skip = (int) $h['extralen'] + (int) $h['commentlen'];
            if ($skip > 0) {
                fseek($this->fp, $skip, SEEK_CUR);
            }

            if ($nameLen === 0 || substr($name, -1) === '/') {
                continue; // 目录条目
            }
            $this->entries[$name] = array(
                'method' => (int) $h['method'],
                'csize' => (int) $h['csize'],
                'usize' => (int) $h['usize'],
                'crc' => (int) $h['crc'],
                'offset' => (int) $h['offset'],
            );
        }

        if (empty($this->entries)) {
            $this->error = '压缩包里没有可读文件';
        }
    }
}
