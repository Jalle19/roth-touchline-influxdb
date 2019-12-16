# roth-touchline-influxdb

A script that pushes measurements from Roth Touchline controllers to InfluxDB. It supports a variable number of 
connected thermostats, but only one controller (i.e. no master/slave configurations). Thermostat measurements are 
tagged using the thermostat name.

Ultimately it enables you to produce Grafana graphs such as this:

![Graphana example graph](https://raw.githubusercontent.com/Jalle19/roth-touchline-influxdb/master/grafana_example_graph.png)

The query used by the graph above looks like this:

![Graphana example query](https://raw.githubusercontent.com/Jalle19/roth-touchline-influxdb/master/grafana_example_query.png)

## Requirements

* PHP with XML support

On Ubuntu/Debian, this can be installed by running:

```bash
sudo apt-get install php-cli php-xml
```

## Usage

Adapt the following example to your own environment:

```bash
php roth-touchline-influxdb.php --controllerIpAddress 10.110.4.1 --influxDbUrl http://10.110.1.6:8086/ --influxDbName roth --influxDbUsername roth --influxDbPassword roth
```

You'll want to run the script at regular intervals, e.g. with cron. Here's an example crontab:

```
* * * * * /usr/bin/php /home/Jalle19/roth-touchline-influxdb/roth-touchline-influxdb.php --controllerIpAddress 10.110.4.1 --influxDbUrl http://10.110.1.6:8086/ --influxDbName roth --influxDbUsername roth --influxDbPassword roth
```

## Technical details

The script performs a single query against InfluxDB each time it is run. The `curl` equivalent looks like this:

```
curl -i -XPOST 'http://10.110.1.6:8086/write?db=roth' --data-binary 'CD CD.upass=1234                                                                                       
Controller R0.DateTime=1576494851,R0.ErrorCode=0,R0.OPModeRegler=0,R0.Safety=0,R0.SystemStatus=0,R0.Taupunkt=0,R0.WeekProgWarn=1,R0.kurzID=233,R0.numberOfPairedDevices=9
STELL STELL-APP=1.42,STELL-BL=1.11
STM STM-APP="A.FA",STM-BL=1.11
VPI VPI.href="http://myroth.ininet.ch/remote/sdfsdfsdf/",VPI.state=99
hw hw.Addr="5C-C2-13-00-23-E3",hw.DNS1="10.110.5.10",hw.DNS2="0.0.0.0",hw.GW="10.110.5.1",hw.HostName="ROTH-0023E3",hw.IP="10.110.4.1",hw.NM="255.255.0.0"
Misc isMaster=true,numberOfSlaveControllers=0,totalNumberOfDevices=9
Thermostat,Thermostat=Förråd RaumTemp=459,WeekProg=0,name="Förråd",TempSIUnit=0
Thermostat,Thermostat=Sovrum RaumTemp=2186,WeekProg=1,name="Sovrum",TempSIUnit=0
Thermostat,Thermostat=Gästrum RaumTemp=2165,WeekProg=1,name="Gästrum",TempSIUnit=0
Thermostat,Thermostat=Bibliotek RaumTemp=2143,WeekProg=1,name="Bibliotek",TempSIUnit=0
Thermostat,Thermostat=Vardagsrum RaumTemp=2199,WeekProg=0,name="Vardagsrum",TempSIUnit=0
Thermostat,Thermostat=Tambur\ /\ WC RaumTemp=2151,WeekProg=0,name="Tambur / WC",TempSIUnit=0
Thermostat,Thermostat=Arbetsrum RaumTemp=2190,WeekProg=1,name="Arbetsrum",TempSIUnit=0
Thermostat,Thermostat=Hjälpkök RaumTemp=2283,WeekProg=0,name="Hjälpkök",TempSIUnit=0'
```

The script abuses the fact that the Roth controller's XML API accepts pretty much arbitrary XML documents as input, 
which makes it easy to get a response that's suitable for parsing and relaying into InfluxDB.

The repository contains samples of the request and response bodies.

## License

GNU GENERAL PUBLIC LICENSE version 3.0
