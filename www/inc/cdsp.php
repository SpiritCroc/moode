<?php
/**
 * moOde audio player (C) 2014 Tim Curtis
 * http://moodeaudio.org
 *
 * This Program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3, or (at your option)
 * any later version.
 *
 * This Program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

/**
 * Wrapper for functionality related to the use of CamillaDSP with moOde
 */

require_once dirname(__FILE__) . '/playerlib.php';


const CDSP_CHECK_VALID = 1;
const CDSP_CHECK_INVALID = 0;
const CDSP_CHECK_NOTFOUND = -1;

const CGUI_CHECK_ACTIVE = 0;
const CGUI_CHECK_INACTIVE = 3;
const CGUI_CHECK_ERROR = -2;
const CGUI_CHECK_NOTFOUND = -1;
class CamillaDsp {

    private $ALSA_CDSP_CONFIG = '/etc/alsa/conf.d/camilladsp.conf';
    private $CAMILLA_CONFIG_DIR = '/usr/share/camilladsp';
    private $CAMILLA_EXE = '/usr/local/bin/camilladsp';
    private $CAMILLAGUI_WORKING_CONGIG = '/usr/share/camilladsp/working_config.yml';
    private $device = NULL;
    private $configfile = NULL;
    private $quickConvolutionConfig = ";;;";

    function __construct ($configfile, $device = NULL, $quickconvfg) {
        $this->configfile =$configfile;
        $this->device = $device;
        $this->quickConvolutionConfig = $this->stringToQuickConvolutionConfig($quickconvfg);

        // Little bit dirty trick:
        // nginx, camillagui and cdsp not all run as the same user but required
        // write rights to the same files.
        // print('chmod -R 666 '. $this->CAMILLA_CONFIG_DIR.'/configs; chmod 666 '. $this->$CAMILLA_CONFIG_DIR.'/coefss/*');
        $this->fixFileRights();
    }

    /**
     * Set in camilladsp config file the playback device to use
     */
    function setPlaybackDevice($device) {
        if( $this->configfile != NULL && $this->configfile != 'off' && $this->configfile != 'custom') {
            $this->device = $device;
            $supportedFormats = $this->detectSupportedSoundFormats();
            $useFormat = count($supportedFormats) >= 1 ?  $supportedFormats[0] : 'S32LE';

            $yml_cfg = yaml_parse_file( $this->getCurrentConfigFileName() );
            $yml_cfg['devices']['capture'] = Array( 'type' => 'File',
                                                    'channels' => 2,
                                                    'filename' => '/dev/stdin',
                                                    'format' => $useFormat);
            $yml_cfg['devices']['playback'] = Array( 'type' => 'Alsa',
                                                'channels' => 2,
                                                'device' => "hw:" . $device . ",0",
                                                'format' => $useFormat);

            yaml_emit_file($this->getCurrentConfigFileName(), $yml_cfg);
        }
    }

    /**
     * Set in the alsa_cdsp config the camilladsp config file to use
     */
    function selectConfig($configname) {
        if($configname != 'custom' && $configname != 'off' && $configname != '') {
            if( $configname == '__quick_convolution__.yml' ) {
                $this->writeQuickConvolutionConfig();
            }

            $configfilename = $this->CAMILLA_CONFIG_DIR . '/configs/' . $configname;
            $configfilename_escaped = str_replace ('/', '\/', $configfilename);
            $this->patchRelConvPath($configname);
            if(is_file($configfilename)) {
                syscmd("sudo ln -s -f \"" . $configfilename . "\" " . $this->CAMILLAGUI_WORKING_CONGIG);
            }
        }

        $this->configfile = $configname;
    }

    function reloadConfig() {
        if( $this->configfile != 'off') {
            syscmd('sudo killall -s SIGHUP camilladsp');
        }
    }

    function getConfig() {
        return $this->configfile;
    }

    /**
     *
     */
    function stringToQuickConvolutionConfig($quickConvConfig) {
        $config= ";;;";
        if($quickConvConfig) {
            $parts = explode(';', $quickConvConfig);
            if( count($parts) == 4 ) {
                $config = array( "gain" => $parts[0],
                                "irl" => $parts[1],
                                "irr" => $parts[2],
                                "irtype" => $parts[3]);
            }
        }
        return $config;
    }

    function setQuickConvolutionConfig($quickConvConfig) {
         $this->quickConvolutionConfig = $quickConvConfig;
    }

    function getQuickConvolutionConfig() {
        return $this->configfile = $this->quickConvolutionConfig;
    }

    function isQuickConvolutionActive() {
        return $this->configfile == '__quick_convolution__.yml';
    }


    function writeQuickConvolutionConfig() {
        $templateFile = $this->CAMILLA_CONFIG_DIR . '/__quick_convolution__.yml';
        $configFile = $this->CAMILLA_CONFIG_DIR . '/configs/__quick_convolution__.yml';
        $lines = file_get_contents($templateFile);

        $search = array('__IR_GAIN__',
                        '__IR_LEFT__',
                        '__IR_RIGHT__',
                        '__IR_FORMAT__');

        $replaceWith = array(   $this->quickConvolutionConfig['gain'],
                                '../coeffs/' . $this->quickConvolutionConfig['irl'],
                                '../coeffs/' . $this->quickConvolutionConfig['irr'],
                                $this->quickConvolutionConfig['irtype'] );

        $newLines = str_replace( $search, $replaceWith, $lines );

        file_put_contents ( $configFile .'.tmp', $newLines) ;
        sysCmd('sudo mv "' . $configFile . '.tmp" "' . $configFile . '"' );
        sysCmd('sudo chmod a+rw "' . $configFile . '"' );
    }

    function copyConfig($source, $destination) {
        copy($this->CAMILLA_CONFIG_DIR . '/configs/' . $source , $this->CAMILLA_CONFIG_DIR . '/configs/' . $destination);
    }

    function newConfig($configname) {
        copy($this->CAMILLA_CONFIG_DIR . '/__config_template__.yml' , $this->CAMILLA_CONFIG_DIR . '/configs/' . $configname);
    }

    function detectSupportedSoundFormats() {
        $available_alsa_sample_formats_from_sound_card_as_string = $_SESSION['audio_formats']; //Sound card sample formats from ALSA
        $available_alsa_sample_formats_from_sound_card = explode (', ', $available_alsa_sample_formats_from_sound_card_as_string);
        $sound_device_supported_sample_formats = array();
        foreach ($this->alsaToCamillaSampleFormatLut() as $alsa_format => $cdsp_format) {
           if (in_array($alsa_format, $available_alsa_sample_formats_from_sound_card)) {
                $sound_device_supported_sample_formats[] = $cdsp_format;
           }
        }

        return $sound_device_supported_sample_formats;
    }

    function getConfigsLocationsFileName() {
        return  $this->CAMILLA_CONFIG_DIR . '/configs/';
    }

    function getCoeffsLocation() {
        return  $this->CAMILLA_CONFIG_DIR . '/coeffs/';
    }

    /**
     * Get the filename of the camilladsp config file to use
     */
    function getCurrentConfigFileName() {
        return $this->CAMILLA_CONFIG_DIR . '/configs/' . $this->configfile;
    }

    /**
     * Check the provided configfile
     * return NULL when config is correct else an array with error messages.
     */
    function checkConfigFile($configname) {
        $configFullPath = $this->CAMILLA_CONFIG_DIR . '/configs/' . $configname;

        $output = array();
        $exitcode = -1;
        if( file_exists($configFullPath)) {
            $cmd = $this->CAMILLA_EXE . " -c \"" . $configFullPath . "\"";
            exec($cmd, $output, $exitcode);
            $exitcode = $exitcode == 0 ? 1 : 0;

        }else {
            $output[] = 'Config file "' . $configFullPath. '" NOT found';
        }
        $result = [];
        $result['valid'] = $exitcode;
        $result['msg'] =  $output;

        return $result;
    }

    /**
     * Returns the basename (filename without path) of the available configs
     */
    function getAvailableConfigsRaw() {
        return $this->getAvailableConfigs(False);
    }

    /**
     * Returns list  available options for the camilladsp setting, including the Off and Custom
     */
    function getAvailableConfigs($extended = True) {
        $configs = [];
        // If extended moode is used, return also Off and custom as selectors
        if( $extended == True ) {
            $configs['off'] = 'Off'; // don't use camilla
            $configs['custom'] = 'Custom'; // custom configuration setup used
            $configs['__quick_convolution__.yml'] = 'Quick convolution filter'; // custom configuration setup used
        }
        foreach (glob($this->CAMILLA_CONFIG_DIR . '/configs/*.yml') as $filename) {
                $fileParts = pathinfo($filename);
                if($fileParts['basename'] != "__quick_convolution__.yml") {
                    $configs[$fileParts['basename']] = $fileParts['filename'];
                }
        }
        return $configs;
    }

    /**
     * Get list available coeffs for convolution
     */
    function getAvailableCoeffs() {
        $configs = [];
        foreach (glob($this->CAMILLA_CONFIG_DIR . '/coeffs/*.*') as $filename) {
            $fileParts = pathinfo($filename);
            $configs[$fileParts['basename']] = $fileParts['filename'];
        }
        return $configs;
    }

    /**
     * Provide coeff info
     */
    function coeffInfo($coefffile, $raw = False) {
        $fileName = $this->CAMILLA_CONFIG_DIR . '/coeffs/'. $coefffile;
        $jsonString = syscmd("mediainfo --Output=JSON \"" . $fileName . "\"");
        $mediaDataObj = json_decode(implode($jsonString));

        $ext = $mediaDataObj->{'media'}->{'track'}[0]->{'FileExtension'};
        $siz = $mediaDataObj->{'media'}->{'track'}[0]->{'FileSize'};
        $rate =$mediaDataObj->{'media'}->{'track'}[1]->{'SamplingRate'};
        $bits =$mediaDataObj->{'media'}->{'track'}[1]->{'BitDepth'};
        $ch = $mediaDataObj->{'media'}->{'track'}[1]->{'Channels'};
        $format =$mediaDataObj->{'media'}->{'track'}[1]->{'Format'};
        $encodingP =$mediaDataObj->{'media'}->{'track'}[1]->{'Format_Profile'};
        $encodingS =$mediaDataObj->{'media'}->{'track'}[1]->{'Format_Settings_Sign'};

        $mediaInfo = Array();
        if($ext)
            $mediaInfo['extension'] = $ext;
        if($format)
            $mediaInfo['format'] = $format;
        if($encodingP)
            $mediaInfo['encoding'] = $encodingP;
        elseif($encodingS)
            $mediaInfo['encoding'] = $encodingS;
        if($rate)
            $mediaInfo['samplerate'] = $raw ? intval($rate) : $rate/1000.0 . ' kHz';
        if($bits)
            $mediaInfo['bitdepth'] = $raw ? intval($bits) : $bits . ' bits';
        if($ch)
            $mediaInfo['channels'] = intval($ch);
        if($siz != NULL)
            $mediaInfo['size'] = $raw ? intval(siz) : sprintf('%.1f kB', $siz/1024.0) ;

        return $mediaInfo;
    }

    function getConfigLabel($configname) {
        $selectedConfigLabel = ($configname != '__quick_convolution__.yml') ? $configname : 'Quick convolution filter';
        return $selectedConfigLabel;
    }

    /**
     * Returns the version of the used CamillaDSP
     */
    function version() {
        $version  = NULL;
        $result = syscmd("camilladsp --version ");

        if(  count($result) == 1 ) {
            $version =  $result[0];
        } else {
            $version = "Error: Unable to detect version of Camilla DSP.";
        }
        return $version;
    }

    /**
     * ALSA sample formats with corresponding CamillaDSP sample formats
     */
    function alsaToCamillaSampleFormatLut() {
        return array(
            'FLOAT64_LE' => 'FLOAT64LE',
            'FLOAT_LE' => 'FLOAT32LE',
            'S32_LE' => 'S32LE',
            'S24_3LE' => 'S24LE3',
            'S24_LE' => 'S24LE',
            'S16_LE' => 'S16LE');
    }

    function impulseResponseType() {
        return array(
           'TEXT',
           'FLOAT64LE',
           'FLOATLE',
           'S32LE',
           'S24LE3',
           'S24LE',
           'S16LE'
        );
    }

    function getCamillaGuiStatus() {
        $output = array();
        $exitcode = CGUI_CHECK_NOTFOUND;
        if( file_exists('/etc/systemd/system/camillagui.service')) {
            $cmd = 'systemctl status camillagui';
            exec($cmd, $output, $exitcode);
        }

        return $exitcode;
    }

    function changeCamillaStatus($enable) {
        if($enable) {
            syscmd("sudo systemctl start camillagui");
        }else {
            syscmd("sudo systemctl stop camillagui");
        }
    }

    function getGuiExpertMode() {
        return file_exists('/opt/camillagui/config/gui-config.yml') == false;
    }

    function setGuiExpertMode($mode) {
        if( $mode == true
           && file_exists('/opt/camillagui/config/gui-config.yml')
           && file_exists('/opt/camillagui/config/gui-config.yml.disabled') == false ) {
            syscmd("sudo mv /opt/camillagui/config/gui-config.yml /opt/camillagui/config/gui-config.yml.disabled");
        }
        else if( $mode == false
                 && file_exists('/opt/camillagui/config/gui-config.yml.disabled')
                 && file_exists('/opt/camillagui/config/gui-config.yml') == false ) {
            syscmd("sudo mv /opt/camillagui/config/gui-config.yml.disabled /opt/camillagui/config/gui-config.yml");
        }
    }

    function _waveConvertOptions($bitdeph, $encoding) {
        // standard supported raw formats
        $conversion_table = [ 'f' =>
                              [ 64 => [64, 'floating-point'],
                                32 => [32, 'floating-point'] ],
                              'i' =>
                              [ 32 => [32, 'signed-integer'],
                                24 => [24, 'signed-integer'] ,
                                16 => [16, 'signed-integer'] ]
                            ];

        // chekc if the src wav is a support dest raw format
        if( array_key_exists($encoding, $conversion_table) && array_key_exists($bitdeph, $conversion_table[$encoding])) {
            return $conversion_table[$encoding][$bitdeph];
        }
        // else just convert it to 32b signed format
        return [32, 'signed-integer'];
    }

    function convertWaveFile($coefffile) {
        $info = $this->coeffInfo($coefffile, TRUE);

        if( isset($info['extension']) && isset($info['channels']) && strtolower($info['extension']) == 'wav' ) {
            $sox_path = '/usr/bin/sox';
            if(file_exists($sox_path)) {
                $bitdepth = intval(explode(" ", $info['bitdepth'])[0]);
                $coding = strtolower($info['encoding'][0]) =='f' ? 'f': 'i';
                $sox_options = $this->_waveConvertOptions($bitdepth, $coding);
                $sox_options_str =  sprintf(' -b %d -e %s ', $sox_options[0], $sox_options[1] );

                $path_parts = pathinfo($coefffile);
                $fileName = $this->CAMILLA_CONFIG_DIR . '/coeffs/'. $coefffile;

                $fileNameRawBase = sprintf('%s/coeffs/%s_%%s%dHz_%db%s.raw', $this->CAMILLA_CONFIG_DIR , $path_parts['filename'], $info['samplerate'], $bitdepth, $coding == 'f'? 'f': '') ;
                $cmds = [];
                if( $info['channels'] == 1 ) {
                    $fileNameRaw = sprintf($fileNameRawBase, '');
                    unlink($fileNameRaw);
                    $cmd = $sox_path .' "' . $fileName . '"' . $sox_options_str . '"' . $fileNameRaw. '"';

                    print($cmd);
                    exec($cmd . " 2>&1", $output);
                    if( file_exists($fileNameRaw) ) {
                        unlink($fileName);
                        $this->fixFileRights();
                        return NULL;
                    }
                    else {
                        $output[] = 'Could not find generated files.';
                        return $output;
                    }
                }else{
                    $fileNameRawL = sprintf($fileNameRawBase, 'L_');
                    $fileNameRawR = sprintf($fileNameRawBase, 'R_');

                    print($fileNameRawL);

                    unlink($fileNameRawL);
                    unlink($fileNameRawR);
                    $cmd = $sox_path .' "' . $fileName . '"' . $sox_options_str . '"' . $fileNameRawL. '" remix 1 ; '. $sox_path .' "' . $fileName . '"' . $sox_options_str . '"' . $fileNameRawR. '" remix 2';
                    print($cmd);
                    exec($cmd . " 2>&1", $output);
                    if( file_exists($fileNameRawL) && file_exists($fileNameRawR)) {
                        unlink($fileName);
                        $this->fixFileRights();
                        return NULL;
                    }
                    else {
                        $output[] = 'Could not find generated files.';
                        return $output;
                    }
                }

            }
            else {
                return ['Sox not found (please install sox)'];
            }
        }
        else {
            return ['File is not a (stereo) wave file.'];
        }

    }


    /**
     * CamillaGUI requires absolute path names, convert rel coeff files to absolute
     */
    function patchRelConvPath($config) {
        if( $config != NULL && $config != 'off' && $config != 'custom') {
            $configfile =  $this->getConfigsLocationsFileName() . $config;
            if( file_exists($configfile)) {
                $coeffsdir  = str_replace ('/', '\/', $this->CAMILLA_CONFIG_DIR . '/coeffs' );
                $cmd = "sed -i -s 's/[.][.]\/coeffs/" . $coeffsdir. "/g' " . $configfile;
                return $this->userCmd($cmd);
            }
            else {
                return 99;
            }
        }
        return 0;
    }

    function fixFileRights() {
        sysCmd('chmod 666 '.$this->CAMILLA_CONFIG_DIR.'/configs/*; sudo chmod 666 '.  $this->CAMILLA_CONFIG_DIR.'/coeffs/*');
    }
    function userCmd($cmd) {
        exec($cmd, $output, $exitcode);
        return $exitcode;
    }

    // placeholders for autoconfig support, empty for now
    function backup() {
    }

    function restore($values) {
    }

}

function test_cdsp() {
    // $cdsp = New CamillaDsp('config_foobar.yaml', "5", "-9;test2.txt;test3.txt;S24_3LE");
    $cdsp = New CamillaDsp('flat.yml', "2", "-9;test2.txt;test3.txt;S24_3LE");



    // print($cdsp->getCurrentConfigFileName() . "\n");
//    $cdsp->setPlaybackDevice(4);
    // $cdsp->selectConfig("config_foobar.yml");
    // print("\n");
    // print_r($cdsp->checkConfigFile("config.good.yml"));
    // print("\n");
    // print_r($cdsp->checkConfigFile("config.bad.yml"));
    // print("\n");
    // print_r($cdsp->checkConfigFile("config.doesnt_exist.yml"));

    // print_r($cdsp->availableConfigs() );
    // print(count($cdsp->checkConfigFile("config.good.yml")));

    // print_r($cdsp->checkConfigFile("config.good.yml"));
    // print(gettype($cdsp->checkConfigFile("config.good.yml")['valid']));
    // if( $cdsp->checkConfigFile("config.good.yml")['valid'] == 1) {
    //     print("config ok \n");
    // }
    // else {
    //     print("config bad \n");
    // }
    // print($cdsp->version());

   print_r($cdsp->detectSupportedSoundFormats());


    // $cdsp->setPlaybackDevice(7);

    // print_r($cdsp->coeffinfo('Sennheiser_HD800S.wav'));
    //$res = $cdsp->coeffInfo('Sennheiser_HD800S.wav');
    //print_r($res);
//    print_r($res->{'media'}->{'track'}[0]->{'Format'});

    // print($res['media']['track'][1]['BitDepth']);

    // $res = $cdsp->coeffInfo('test1.txt');
    // print_r($res);
    //     $cdsp->changeCamillaStatus(0);
    //     print_r( $cdsp->getCamillaGuiStatus() );
    //     print("\n");
    //     $cdsp->changeCamillaStatus(1);
    //     print_r( $cdsp->getCamillaGuiStatus() );
    //     print("\n");

    //$cdsp->copyConfig('config_hp.yml', 'fliepflap.yml');

    //$cdsp->setGuiExpertMode(true);
    //$cdsp->setGuiExpertMode(false);
    // $cdsp->convertWave2Raw('BRIR_R02_P1_E0_A30_L.wav');



    // print_r($cdsp->convertWaveFile('test_samplerate_44100Hz.wav'));
    // print_r($cdsp->convertWaveFile('Sennheiser_HD800S_L.wav'));
    // print_r($cdsp->convertWaveFile('BRIR_R02_P1_E0_A30C_44100Hz_24b.raw'));
    // $cdsp->setPlaybackDevice(2);
}



if (basename(__FILE__) == basename($_SERVER["SCRIPT_FILENAME"])) {
    test_cdsp();
}


?>
