<?php

/*
	Phoronix Test Suite
	URLs: http://www.phoronix.com, http://www.phoronix-test-suite.com/
	Copyright (C) 2008 - 2009, Phoronix Media
	Copyright (C) 2008 - 2009, Michael Larabel
	phodevi_cpu.php: The PTS Device Interface object for the CPU / processor

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation; either version 3 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program. If not, see <http://www.gnu.org/licenses/>.
*/

class phodevi_cpu extends pts_device_interface
{
	public static function read_property($identifier)
	{
		switch($identifier)
		{
			case "identifier":
				$property = new pts_device_property("phodevi_cpu", "cpu_string", true);
				break;
			case "model":
				$property = new pts_device_property("phodevi_cpu", "cpu_model", true);
				break;
			case "default-frequency":
				$property = new pts_device_property("phodevi_cpu", array("cpu_default_frequency", 0), true);
				break;
			case "core-count":
				$property = new pts_device_property("phodevi_cpu", "cpu_core_count", true);
				break;
			case "power-savings-mode":
				$property = new pts_device_property("phodevi_cpu", "cpu_power_savings_mode", true);
				break;
			default:
				$property = new pts_device_property(null, null, false);
				break;
		}

		return $property;
	}
	public static function cpu_string()
	{
		return phodevi::read_property("cpu", "model") . " (Total Cores: " . phodevi::read_property("cpu", "core-count") . ")";
	}
	public static function cpu_core_count()
	{
		if(IS_LINUX)
		{
			$info = count(read_cpuinfo("processor"));
		}
		else if(IS_SOLARIS)
		{
			$info = count(explode("\n", trim(shell_exec("psrinfo"))));
		}
		else if(IS_BSD)
		{
			$info = intval(read_sysctl("hw.ncpu"));
		}
		else if(IS_MACOSX)
		{
			$info = read_osx_system_profiler("SPHardwareDataType", "TotalNumberOfCores");	
		}

		return (is_int($info) && $info > 0 ? $info : 1);
	}
	public static function cpu_default_frequency($cpu_core = 0)
	{
		// Find out the processor frequency
		// First, the ideal way, with modern CPUs using CnQ or EIST and cpuinfo reporting the current
		if(is_file("/sys/devices/system/cpu/cpu" . $cpu_core . "/cpufreq/scaling_max_freq"))
		{
			$info = trim(file_get_contents("/sys/devices/system/cpu/cpu" . $cpu_core . "/cpufreq/scaling_max_freq"));
			$info = pts_trim_double(intval($info) / 1000000, 2);
		}
		else if(is_file("/proc/cpuinfo")) // fall back for those without cpufreq
		{
			$cpu_speeds = read_cpuinfo("cpu MHz");
			$cpu_core = (isset($cpu_speeds[$cpu_core]) ? $cpu_core : 0);
			$info = pts_trim_double($cpu_speeds[$cpu_core] / 1000, 2);
		}
		else
		{
			$info = hw_cpu_current_frequency($cpu_core);
		}

		return $info;
	}
	public static function cpu_power_savings_mode()
	{
		// Report string if CPU power savings feature is enabled
		$return_string = "";

		if(is_file("/sys/devices/system/cpu/cpu0/cpufreq/scaling_cur_freq") && is_file("/sys/devices/system/cpu/cpu0/cpufreq/scaling_max_freq"))
		{
			// if EIST / CnQ is disabled, the cpufreq folder shoudln't be present, but double check by comparing the min and max frequencies
			$min = trim(file_get_contents("/sys/devices/system/cpu/cpu0/cpufreq/scaling_min_freq"));
			$max = trim(file_get_contents("/sys/devices/system/cpu/cpu0/cpufreq/scaling_max_freq"));

			if($min < $max)
			{
				$cpu = phodevi::read_property("cpu", "model");

				if(strpos($cpu, "AMD") !== false)
				{
					$return_string = "AMD Cool n Quiet was enabled";
				}
				else if(strpos($cpu, "Intel") !== false)
				{
					$return_string = "Intel SpeedStep Technology was enabled";
				}
				else
				{
					$return_string = "The CPU was in a power-savings mode";
				}
			}
		}

		return $return_string;
	}
	public static function cpu_model()
	{
		// Returns the processor name / frequency information
		$info = "";

		if(IS_LINUX)
		{
			$physical_cpu_ids = read_cpuinfo("physical id");
			$physical_cpu_count = count(array_unique($physical_cpu_ids));

			$cpu_strings = read_cpuinfo("model name");
			$cpu_strings_unique = array_unique($cpu_strings);

			if($physical_cpu_count == 1 || empty($physical_cpu_count))
			{
				// Just one processor
				$info = $cpu_strings[0];
			}
			else if($physical_cpu_count > 1 && count($cpu_strings_unique) == 1)
			{
				// Multiple processors, same model
				$info = $physical_cpu_count . " x " . $cpu_strings[0];
			}
			else if($physical_cpu_count > 1 && count($cpu_strings_unique) > 1)
			{
				// Multiple processors, different models
				$current_id = -1;
				$current_string = $cpu_strings[0];
				$current_count = 0;
				$cpus = array();

				for($i = 0; $i < count($physical_cpu_ids); $i++)
				{
					if($current_string != $cpu_strings[$i] || $i == (count($physical_cpu_ids) - 1))
					{
						array_push($cpus, $current_count . " x " . $current_string);

						$current_string = $cpu_strings[$i];
						$current_count = 0;
					}

					if($physical_cpu_ids[$i] != $current_id)
					{
						$current_count++;
						$current_id = $physical_cpu_ids[$i];
					}
				}
				$info = implode(", ", $cpus);
			}
		}
		else if(IS_SOLARIS)
		{
			$dmi_cpu = read_sun_ddu_dmi_info("ProcessorName");

			if(count($dmi_cpu) > 0)
			{
				$info = $dmi_cpu[0];
			}
			else
			{
				$info = trim(shell_exec("dmesg 2>&1 | grep cpu0"));
				$info = trim(substr($info, strrpos($info, "cpu0:") + 6));

				if(empty($info))
				{
					$info = array_pop(read_sun_ddu_dmi_info("ProcessorManufacturer"));
				}
			}

			//TODO: Add in proper support for reading multiple CPUs, similar to the code from above
			$physical_cpu_count = count(read_sun_ddu_dmi_info("ProcessorSocketType"));
			if($physical_cpu_count > 1 && !empty($info))
			{
				// TODO: For now assuming when multiple CPUs are installed, that they are of the same type
				$info = $physical_cpu_count . " x " . $info;
			}
		}
		else if(IS_BSD)
		{
			$info = read_sysctl("hw.model");
		}
		else if(IS_MACOSX)
		{
			$info = read_osx_system_profiler("SPHardwareDataType", "ProcessorName");
		}

		if(!empty($info))
		{
			$info = pts_clean_information_string($info);

			if($append_cpu_frequency)
			{
				// Append the processor frequency to string
				if(($freq = phodevi::read_property("cpu", "default-frequency")) > 0)
				{
					if(($strip_point = strpos($info, "@")) > 0)
					{
						$info = trim(substr($info, 0, $strip_point)); // stripping out the reported freq, since the CPU could be overclocked, etc
					}

					$info .= " @ " . $freq . "GHz";
				}
			}
		}
		else
		{
			$info = "Unknown";
		}

		return $info;
	}
}

?>
