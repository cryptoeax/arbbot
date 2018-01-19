var graphMode = "0";
var graphExchange = "0";
var graphCoin = "BTC";

var stats = null;
var statsTimeout = null;
var totalPL = null;
var realizedPL = null;
var profitableTrades = null;
var plMode = "summary";
var uiDisabled = false;
var adminUIReady = false;

var botStatus = null;
var hideStatus = false;

$(function() {

    $(document).on("click", ".showGraph", function() {

        var coin = $(this).attr("coin");
        var exid = $(this).attr("exchange");
        var mode = $(this).attr("mode");

        graphMode = mode;
        graphExchange = exid;
        graphCoin = coin;

        updateGraph();
        return false;
    });

    $(document).on("change", "#smooth", function() {
        var smooth = $("#smooth")[0].checked;
        Cookies.set('smooth', smooth);

        updateGraph();
        return false;
    });

    $(".showPLSummary").click(function() {
        plMode = "summary";
        updatePL();
        return false;
    });

    $(".showPLDetails").click(function() {
        plMode = "details";
        updatePL();
        return false;
    });

    $(".showPLGraph").click(function() {
        plMode = "graph";
        updatePL();
        return false;
    });

    $(".showPLCoinGraph").click(function() {
        plMode = "graphCoin";
        updatePL();
        return false;
    });

    $(document).on("change", "#filterbox select", function() {
        updatePL();
        return false;
    });

    function initSmoothCheckbox() {
      var smoothCookie = Cookies.get('smooth');
      if (smoothCookie == 'true') {
        $("#smooth")[0].checked = true;
      }
    }

    function onHideZeroChange() {
        var checked = $('#hidezero')[0].checked;
        Cookies.set('hidezero', checked);
        if (checked) {
            $("#wallets").addClass("hide-zero");
        } else {
            $("#wallets").removeClass("hide-zero");
        }
        return false;
    }
    $(document).on("change", "#hidezero", onHideZeroChange);

    var walletAge = 0;

    function no2e(x, title) {

        var num = parseInt(x);
        if (num <= 9 && num >= 0) {
	    return '<span title="' + (title ? title : no2ell_xfer(x)) + '">' +
                   '<img src="xchange/' + num + '.ico" width="14" height="14">' +
                   '</span>';
        }

        return "?";

    }

    function no2el(x) {

        if (x === "0" || x === 0)
            return "TOTAL";
        else if (x === "1" || x === 1)
            return "PLNX";
        else if (x === "2" || x === 2)
            return "BLTRD";
        else if (x === "3" || x === 3)
            return "BTTRX";
        else if (x === "7" || x === 7)
            return "HTBTC";
        else if (x === "9" || x === 9)
            return "BINCE";
        else
            return "?";

    }

    function no2ell_xfer(x) {
        // For transfers, an exchange ID of 0 denotes a profit withdrawal.
        if (x === "0" || x === 0)
            return "Profit Withdrawal";
        return no2ell(x);
    }

    function no2ell(x) {

        if (x === "0" || x === 0)
            return "Total";
        else if (x === "1" || x === 1)
            return "Poloniex";
        else if (x === "2" || x === 2)
            return "Bleutrade";
        else if (x === "3" || x === 3)
            return "Bittrex";
        else if (x === "7" || x === 7)
            return "HitBTC";
        else if (x === "9" || x === 9)
            return "Binance";
        else
            return "?";

    }

    function rnd1(x) {
        return Math.round(x * 10) / 10;
    }

    function rnd2(x) {
        return Math.round(x * 100) / 100;
    }

    function rnd4(x) {
        return (Math.round(x * 10000) / 10000).toFixed(4);
    }

    function rnd8(x) {
        return (Math.round(x * 100000000) / 100000000).toFixed(8);
    }

    function fmtpl(x) {
        return "<span class=\"" + (x < 0 ? "neg" : "pos") + "\">" + rnd8(Math.abs(x)) + "</span>";
    }

    function getIcon(symbol) {
        if (symbol === "alt_btc") {
            return ""; // Not a coin. Has no icon.
        }
        symbol = symbol.trim();
        // Canonicalize symbol
        if (symbol == "SC") {
          symbol = "SIA";
        } else if (symbol == "BLK") {
          symbol = "BC";
        }
 
        return "<i class=\"cc " + symbol + "\" title=\"" + symbol + "\"/> ";
    }

    function updateGraph() {
        setTimeout(updateGraph, 90000);

        var coinIcon = getIcon(graphCoin);

        if (graphMode === "0") {
            $("#graphHeading").html(coinIcon + graphCoin + " balance @ " + no2el(graphExchange));
        } else if (graphMode === "1") {
            $("#graphHeading").html(coinIcon + graphCoin + " rate @ " + no2el(graphExchange));
        } else {
            $("#graphHeading").html(coinIcon + graphCoin + " desired balance @ " + no2el(graphExchange));
        }

        $.ajax({
            url: "ajax.php?func=graph&coin=" + graphCoin + "&exchange=" + graphExchange + "&mode=" + graphMode,
            type: "GET",
            cache: false,
            success: function(data) {

                var obj = {};
                var smooth = $("#smooth")[0].checked;

                for (var x = 0; x < data.length; x++) {
                    var d1 = [data[x].time * 1000, smooth ? data[x].value : data[x].raw];
                    var exchange = data[x].exchange;
                    if (exchange === undefined) {
                        continue;
                    }
                    if (!obj[exchange]) {
                        obj[exchange] = [];
                    }
                    obj[exchange].push(d1);
                }

                var exchanges = Object.keys(obj);
                var arrs = [];
                for (i = 0; i < exchanges.length; i++) {
                    arrs.push({ data: obj[exchanges[i]], label: no2el(exchanges[i]) });;
                }


                var endDate = new Date();
                var startDate = new Date();
                startDate.setHours(endDate.getHours() - 48);

                var plot = $.plot("#placeholder", arrs, {
                    label: no2el(graphExchange),
                    legend: {
                        show: true,
                        position: "nw",
                        backgroundOpacity: 0,
                        noColumns: 1
                    },
                    zoom: {
                        interactive: true
                    },
                    pan: {
                        interactive: true
                    },
                    grid: {
                        hoverable: true,
                    },
                    tooltip: {
                        show: true,
                        content: "%s: %y @ %x"
                    },
                    yaxis: {
                        tickFormatter: function(val, axis) {
                          if (Math.abs(val) > 1) {
                            return rnd2(val);
                          } else {
                            return rnd8(val);
                          }
                        }
                    },
                    xaxis: {
                        mode: "time",
                        timeformat: "%b %e\n%H:%M",
                        timezone: "browser",
                        min: startDate.getTime(),
                        max: endDate.getTime()
                    }
                });
            }
        });
    }

    function updateAlerts() {
        setTimeout(updateAlerts, 60 * 60 * 1000);
        $.ajax({
            url: "ajax.php?func=alerts",
            type: "GET",
            cache: false,
            success: function(data) {

                var htmlData = "<pre>";
                for (var i = 0; i < data.length; i++) {
                    var date = new Date(data[i].time * 1000);
                    var year = date.getFullYear();
                    var month = date.getMonth() + 1;
                    var day = date.getDate();
                    var hours = date.getHours();
                    var minutes = date.getMinutes();
                    var seconds = date.getSeconds();
                    if (month < 10)
                        month = "0" + month;
                    if (day < 10)
                        day = "0" + day;
                    if (hours < 10)
                        hours = "0" + hours;
                    if (minutes < 10)
                        minutes = "0" + minutes;
                    if (seconds < 10)
                        seconds = "0" + seconds;
                    var formattedTime = year + '-' + month + '-' + day + ' ' + hours + ':' + minutes + ':' + seconds;
                    htmlData += "[" + formattedTime + "] " + data[i].message + "\n";
                }

                $("#alerts").html("<pre>" + htmlData + "</pre>");
            }
        });
    }

    function updateLog() {
        setTimeout(updateLog, 10000);
        $.ajax({
            url: "ajax.php?func=log",
            type: "GET",
            cache: false,
            success: function(data) {

                var htmlData = "<pre>";
                for (var i = 0; i < data.length; i++) {
                    var date = new Date(data[i].time * 1000);
                    var hours = date.getHours();
                    var minutes = date.getMinutes();
                    var seconds = date.getSeconds();
                    if (hours < 10)
                        hours = "0" + hours;
                    if (minutes < 10)
                        minutes = "0" + minutes;
                    if (seconds < 10)
                        seconds = "0" + seconds;
                    var formattedTime = hours + ':' + minutes + ':' + seconds;
                    htmlData = "[" + formattedTime + "] " + data[i].message + "\n" + htmlData;
                }

                $("#logfile").html("<pre>" + htmlData + "</pre>");
            }
        });
    }

    function updatePL() {
        var timeout = setTimeout(updatePL, 10000);
        $.ajax({
            url: "ajax.php?func=pl",
            type: "GET",
            cache: false,
            data: {
                mode: plMode,
            },
            success: function(data) {

                totalPL = rnd4(data.pl) + data.pl_currency;
                realizedPL = rnd4(data.realized_pl) + data.pl_currency;
                profitableTrades = data.efficiency;

                var arr = data.data;

                if (plMode == "summary" || plMode == "details") {
    	        $("#filterbox").remove();

                    var htmlData = "";
                    htmlData += "<tr><th>Date</th><th>Coin</th>";
                    htmlData += "<th>Source</th><th>Target</th>";
                    htmlData += "<th title=\"Amount sold in the tradeable asset\">Amount</th>";
                    htmlData += "<th title=\"Amount bought in " + data.pl_currency + "\">Bought</th>";
                    htmlData += "<th title=\"Amount sold in " + data.pl_currency + "\">Sold</th>";
                    htmlData += "<th title=\"Gross revenue in " + data.pl_currency + "\">Revenue</th>";
                    htmlData += "<th title=\"Transfer fee\">Tx Fee</th>";
                    htmlData += "<th title=\"Profit/Loss in " + data.pl_currency + "\">Profit/Loss</th></tr>";

                    for (var i = 0; i < arr.length; i++) {
                        htmlData += "<tr>";

                        var date = new Date(arr[i].time * 1000);
                        var year = date.getFullYear();
                        var month = date.getMonth() + 1;
                        var day = date.getDate();
                        var hours = date.getHours();
                        var minutes = date.getMinutes();
                        var seconds = date.getSeconds();
                        if (month < 10)
                            month = "0" + month;
                        if (day < 10)
                            day = "0" + day;
                        if (hours < 10)
                            hours = "0" + hours;
                        if (minutes < 10)
                            minutes = "0" + minutes;
                        if (seconds < 10)
                            seconds = "0" + seconds;
                        var formattedTime = year + '-' + month + '-' + day + ' ' + hours + ':' + minutes + ':' + seconds;

                        htmlData += "<td>" + formattedTime + "</td><td>" + arr[i].coin + "</td><td>" + no2el(arr[i].source_exchange) + "</td><td>" +
                                    no2el(arr[i].target_exchange) + "</td>";
                        htmlData += "<td class=\"plain\">" + rnd8(arr[i].amount_sold).padStart(14, ' ') + "</td>";
                        htmlData += "<td>" + rnd8(Math.abs(arr[i].currency_bought)) + "</td>";
                        htmlData += "<td>" + rnd8(arr[i].currency_sold) + "</td>";
                        htmlData += "<td>" + fmtpl(arr[i].currency_revenue) + "</td>";
                        htmlData += "<td>" + rnd8(arr[i].tx_fee) + "</td>";
                        htmlData += "<td>" + fmtpl(arr[i].currency_pl) + "</td>";
                        htmlData += "</tr>";
                    }

                    $("#pl").html("<table>" + htmlData + "</table>");
                } else if (plMode == "graph" || plMode == "graphCoin") {
                    // Reset the graph every two minutes to allow the user to have time to interact with it!
                    clearTimeout(timeout);
                    setTimeout(updatePL, 120000);

                    var filter = (plMode == "graph") ?
                      "Filter on altcoin" : "Filter on date";
                    var activeFilter;
                    if ($("#filterbox select").length) {
                      activeFilter = $("#filterbox select")[0].value;
                      // Clear the active filter when switching between chart types.
                      if (/^\d{4}-\d{2}-\d{2}$/.test(activeFilter) !=
                          (plMode == "graphCoin")) {
                        activeFilter = undefined;
                      }
                    }

                    var obj = {};
                    var filterData = [];
                    for (var i = 0; i < arr.length; i++) {
                        htmlData += "<tr>";

                        var date = new Date(arr[i].time * 1000);
                        var year = date.getFullYear();
                        var month = date.getMonth() + 1;
                        var day = date.getDate();
                        if (month < 10)
                            month = "0" + month;
                        if (day < 10)
                            day = "0" + day;
                        var formattedTime = year + '-' + month + '-' + day;

                        var key, ignore;
                        if (plMode == "graph") {
                          key = formattedTime;
                          filterData[arr[i].coin] = 1;
                          if (activeFilter && arr[i].coin != activeFilter) {
                            continue;
                          }
                        } else {
                          key = arr[i].coin;
                          filterData[formattedTime] = 1;
                          if (activeFilter && formattedTime != activeFilter) {
                            continue;
                          }
                        }
                        if (!(key in obj)) {
                            obj[key] = {
                              value: 0,
                              count: 0,
                              success: 0
                            };
                        }
                        obj[key].value += arr[i].currency_pl;
                        obj[key].count++;
                        if (arr[i].currency_pl >= 0) {
                            obj[key].success++;
                        }
                    }
                    filterData = Object.keys(filterData);
                    var collator = new Intl.Collator(undefined, { numeric: true, sensitivity: "base" });
                    filterData.sort(collator.compare);

                    var ticks = [];
                    var data = [];
                    var success = [];
                    var count = [];
                    var keys = Object.keys(obj);
                    var min = 0, max = 0;
                    var minS = 0, maxS = 0;
                    var minC = 0, maxC = 0;
                    var N = keys.length - 1;
                    for (var i = N; i >= 0; i--) {
                        ticks.push([N - i, getIcon(keys[i]) + keys[i]]);
                        data.push([N - i, obj[keys[i]].value]);
                        success.push([N - i, 100 * obj[keys[i]].success /
                                                   obj[keys[i]].count]);
                        count.push([N - i, obj[keys[i]].count]);
                        if (obj[keys[i]].value > max) {
                            max = obj[keys[i]].value;
                        }
                        if (obj[keys[i]].value < min) {
                            min = obj[keys[i]].value;
                        }
                        if (obj[keys[i]].success > maxS) {
                            maxS = obj[keys[i]].success;
                        }
                        if (obj[keys[i]].success < minS) {
                            minS = obj[keys[i]].success;
                        }
                        if (obj[keys[i]].count > maxC) {
                            maxC = obj[keys[i]].count;
                        }
                        if (obj[keys[i]].count < minC) {
                            minC = obj[keys[i]].count;
                        }
                    }
                    arr = [
                        {
                            label: "Unrealized daily P&L (BTC)",
                            data: data,
                            bars: {
                                order: 1,
                            },
                            yaxis: 1
                        },
                        {
                            label: "Profitable trades (%)",
                            data: success,
                            bars: {
                                order: 2,
                            },
                            yaxis: 2
                        },
                        {
                            label: "Number of trades",
                            data: count,
                            bars: {
                                order: 3,
                            },
                            yaxis: 3
                        },
                    ];

                    var ratioS = (maxS - minS) / (max - min);
                    var ratioC = (maxC - minC) / (max - min);
                    $.plot("#pl", arr, {
                        legend: {
                            show: true,
                            position: "nw",
                            backgroundOpacity: 0
                        },
                        zoom: {
                            interactive: true
                        },
                        pan: {
                            interactive: true
                        },
                        xaxis: {
                            ticks: ticks,
                        },
                        yaxis: {
                            tickFormatter: function (val, axis) {
                                if (axis.n === 1) {
                                    return rnd8(val);
                                } else if (axis.n === 2) {
                                    return parseFloat(val).toFixed(2);
                                } else {
                                    return val;
                                }
                            }
                        },
                        yaxes: [{
                            position: "left",
                            min: min,
                            max: max
                        }, {
                            position: "right",
                            min: ratioS * min,
                            max: ratioS * max
                        }, {
                            position: "right",
                            min: ratioC * min,
                            max: ratioC * max
                        }],
                        grid: {
                            hoverable: true,
                            borderWidth: 2,
                        },
                        tooltip: {
                            show: true,
                            content: "%s: %y"
                        },
                        series: {
                            bars: {
                                zero: false,
                                show: true
                            }
                        },
                        bars: {
                            align: "center",
                            barWidth: 0.1
                        },
                    }).zoom({ amount: 0.5 });

                    var htmlData = "<div id=\"filterbox\">" + filter + " ";
                    htmlData += "<select><option value=\"\">All</option>";
                    for (var i = 0; i < filterData.length; ++i) {
                      htmlData += "<option";
                      if (activeFilter == filterData[i]) {
                        htmlData += " selected";
                      }
                      htmlData += ">" + filterData[i] + "</option>";
                    }
                    htmlData += "</select>";
                    htmlData += "</div>";
                    $("#filterbox").remove();
                    $(".pl-container").append(htmlData);
                }
            }
        });
    }

    function timeLeft(futureTime) {

        var delta = (futureTime * 1000 - new Date()) / 1000;
        if (delta < 0) {
            return "soon";
        }

        var hours = new String(Math.floor(delta / 3600));
        delta -= hours * 3600;
        if (hours.length < 2)
            hours = "0" + hours;

        var minutes = new String(Math.floor(delta / 60) % 60);
        delta -= minutes * 60;
        if (minutes.length < 2)
            minutes = "0" + minutes;

        var seconds = new String(Math.floor(delta));
        if (seconds.length < 2)
            seconds = "0" + seconds;

        return hours + ":" + minutes + ":" + seconds;
    }

    function updateAge() {
        setTimeout(updateAge, 1000);
        walletAge++;

        var age;
        if (walletAge > 60) {
            age = Math.floor(walletAge / 60) + "m";
        } else {
            age = walletAge + "s";
        }
        $("#wage").html("Wallets (" + age + ")");
    }

    function updateManagement() {
        setTimeout(updateManagement, 60000);
        $.ajax({
            url: "ajax.php?func=management",
            type: "GET",
            cache: false,
            success: function(data) {

                var htmlData = "";
                for (var i = 0; i < data.length && i <= 15; i++) {
                    var coin = data[i].coin;
                    while (coin.length < 5) {
                        coin = coin + " ";
                    }
                    var amount = String(rnd1(data[i].amount));
                    if (data[i].amount > 0)
                        amount = "+" + amount;

                    while (amount.length < 7) {
                        amount = " " + amount;
                    }

                    htmlData += amount + " " + getIcon(coin) + coin;
                    htmlData += " ";
                    htmlData += no2e(data[i].exchange);
                    htmlData += " ";
                    htmlData += no2el(data[i].exchange);
                    htmlData += "\n";
                }

                $("#management").html("<pre>" + htmlData + "</pre>");
            }
        });
    }

    function updateXfers() {
        setTimeout(updateXfers, 60000);
        $.ajax({
            url: "ajax.php?func=xfer",
            type: "GET",
            cache: false,
            success: function(data) {

                var htmlData = "";
                for (var i = 0; i <= 15 && i < data.length; i++) {
                    var coin = data[i].coin;
                    while (coin.length < 5) {
                        coin = coin + " ";
                    }
                    var amount = new String(data[i].amount);
                    amount = amount.substr(0, amount.indexOf('.') + 3);
                    while (amount.length < 8) {
                        amount = " " + amount;
                    }

                    var profit = new String(data[i].profit);
                    profit = profit.substr(0, profit.indexOf('.') + 5);
                    profit = profit + " BTC";
                    while (profit.length < 11) {
                        profit = " " + profit;
                    }

                    var direction = "";
                    direction += no2e(data[i].exchange_source);
                    direction += " -> ";
                    direction += no2e(data[i].exchange_target);

                    htmlData += amount + " " + getIcon(coin) + coin + " " + direction + "\n";
                }

                $("#xfers").html("<pre>" + htmlData + "</pre>");
            }
        });
    }

    function updateTrades() {
        setTimeout(updateTrades, 60000);
        $.ajax({
            url: "ajax.php?func=trades",
            type: "GET",
            cache: false,
            success: function(data) {

                var htmlData = "";
                for (var i = 0; i <= 15 && i < data.length; i++) {

                    var coin = new String(data[i].coin);
                    while (coin.length < 5) {
                        coin = coin + " ";
                    }

                    var amount = new String(data[i].amount);
                    amount = amount.substr(0, amount.indexOf('.') + 3);
                    while (amount.length < 8) {
                        amount = " " + amount;
                    }
                    htmlData += amount + " " + getIcon(coin) + coin + " " + no2e(data[i].source) + " -> " + no2e(data[i].target) + "\n";
                }

                $("#trades").html("<pre>" + htmlData + "</pre>");
            }
        });
    }

    function formatBalance(b) {
        var rval = new String(rnd8(b));
        rval = rval.substr(0, rval.indexOf('.') + 3);
        return rval;
    }

    function genWhitespace(b, len) {
        var rval = "";
        while (rval.length + b.length < len) {
            rval = " " + rval;
        }
        return rval;
    }

    function fmt2(b) {
        var rval = new String(Math.min(99, b));
        if (rval.length < 2) {
            rval = "0" + rval;
        }
        return rval;
    }

    function fmt5(b) {
        var rval = new String(Math.min(99999, b));
        if (rval.length < 5) {
            rval = "0" + rval;
        }
        return rval;
    }

    function updateWallets() {
        setTimeout(updateWallets, 60000);
        $.ajax({
            url: "ajax.php?func=wallets",
            type: "GET",
            cache: false,
            success: function(data) {

                var wallets = data['wallets'];
                walletAge = data['age'];
                var tradeCounts = data['trades'];
                var useCounts = data['uses'];

                var htmlData = "----- <span title=\"Total Portfolio\">TOTAL PORTFOLIO</span> -----\n";

                // sum up altcoin totals
                var altcoinTotals = {};
                Object.keys(wallets).forEach(function(coin) {
                    Object.keys(wallets[coin]).forEach(function(xid) {
                        if (xid in altcoinTotals) {
                            altcoinTotals[xid] += wallets[coin][xid]['balance_BTC'];
                        } else {
                            altcoinTotals[xid] = wallets[coin][xid]['balance_BTC'];
                        }
                    });
                }); 
                var altcoinBalance = 0;
                Object.keys(altcoinTotals).forEach(function(xid) {
                    var exname = no2el(xid);
                    while (exname.length < 5) {
                        exname = " " + exname;
                    }
                    
                    htmlData += exname;
                    htmlData += ": ";
                    htmlData += "<a href=\"#\" coin=\"alt_btc\" exchange=\"" + xid + "\" mode=\"0\" class=\"showGraph\" title=\"Total balance (BTC + Altcoins)\">";
                    htmlData += rnd4(altcoinTotals[xid]);
                    htmlData += "</a>\n";
                    altcoinBalance += parseFloat(altcoinTotals[xid]);
                });

                htmlData += "TOTAL: ";
                htmlData += "<a href=\"#\" coin=\"alt_btc\" exchange=\"0\" mode=\"0\" class=\"showGraph\" title=\"Total balance (BTC + Altcoins)\">";
                htmlData += rnd4(altcoinBalance);
                htmlData += "</a>\n";

                htmlData += "--------------------------\n";
                htmlData += "\n";

                htmlData += "--------- " + getIcon("BTC") + "<span title=\"Currency\">BTC</span> ----------\n";

                var btcData = wallets['BTC'];
                var total = 0;
                var totalChange = 0;

                Object.keys(btcData).forEach(function(xid) {

                    var exname = no2el(xid);
                    while (exname.length < 5) {
                        exname = " " + exname;
                    }

                    htmlData += exname;
                    htmlData += ": ";
                    htmlData += "<a href=\"#\" coin=\"BTC\" exchange=\"" + xid + "\" mode=\"0\" class=\"showGraph\" title=\"Total balance (BTC)\">";
                    htmlData += rnd4(btcData[xid]['balance']);
                    htmlData += "</a>";
                    htmlData += " T:";
                    htmlData += "<span title=\"Trade counts\">";
                    htmlData += fmt5(tradeCounts[xid]);
                    htmlData += "</span>|O:";
                    htmlData += "<span title=\"Opportunity counts\">";
                    htmlData += fmt5(useCounts[xid]);
                    htmlData += "</span>\n";
                    total += parseFloat(btcData[xid]['balance']);
                    totalChange += parseFloat(btcData[xid]['change']);
                });

                htmlData += "TOTAL: ";
                htmlData += "<a href=\"#\" coin=\"BTC\" exchange=\"0\" mode=\"0\" class=\"showGraph\" title=\"Total balance (BTC)\">";
                htmlData += rnd4(total);
                htmlData += "</a>";
                htmlData += " <span title=\"Total balance change (BTC)\">";
                htmlData += totalChange > 0 ? "+" : "";
                htmlData += rnd4(totalChange);
                htmlData += "</span>\n";
                htmlData += "--------------------------\n";
                htmlData += "\n";

                htmlData += "<input type=\"checkbox\" id=\"hidezero\"";
                var hzCookie = Cookies.get('hidezero');
                if (hzCookie == 'true') {
                    htmlData += " checked";
                    setTimeout(onHideZeroChange, 100);
                }
                htmlData += "> <label for=\"hidezero\">Hide zero balances</label>\n";

                Object.keys(wallets).forEach(function(coin) {

                    if (coin === "BTC") {
                        return;
                    }

                    var dataset = wallets[coin];
                    var valid = false;
                    Object.keys(dataset).forEach(function(xid) {
                        valid |= dataset[xid]['opportunities'] > 0 || dataset[xid]['balance'] > 0;
                    });

                    if (!valid) {
                        return;
                    }

                    var totalBalance = 0;
                    Object.keys(dataset).forEach(function(xid) {
                        totalBalance += dataset[xid].balance;
                    });
                    if (totalBalance == 0) {
                        htmlData += "<span class=\"zero\">";
                    }

                    var dashes = "";
                    var strCoin = coin + " ";
                    while ((strCoin + dashes).length < 23) {
                        dashes += "-";
                    }
                    htmlData += getIcon(coin) + "<span title=\"Currency\">" + strCoin + "</span> ";
                    htmlData += dashes + "\n";


                    total = 0;
                    Object.keys(dataset).forEach(function(xid) {

                        var dat = dataset[xid];

                        var diff = new String(Math.floor(dat.balance_diff));

                        var balance = formatBalance(dat.balance);
                        var balws = genWhitespace(balance, 6);

                        htmlData += "<a href=\"#\" coin=\"" + coin + "\" exchange=\"" + xid + "\" mode=\"1\" class=\"showGraph\">";
                        htmlData += no2e(xid, no2ell(xid));
                        htmlData += "</a>";
                        htmlData += " = ";

                        htmlData += balws;
                        htmlData += "<a href=\"#\" coin=\"" + coin + "\" exchange=\"" + xid + "\" mode=\"0\" class=\"showGraph\" title=\"Total balance\">";
                        htmlData += balance;
                        htmlData += "</a>";

                        htmlData += " (<span title=\"Total opportunities\">";
                        htmlData += fmt5(dat.opportunities);
                        htmlData += "</span>|<span title=\"Total trades\">";
                        htmlData += fmt5(dat.trades);
                        htmlData += "</span>|";

                        htmlData += "<a href=\"#\" coin=\"" + coin + "\" exchange=\"" + xid + "\" mode=\"2\" class=\"showGraph\" title=\"Balance change\">";
                        htmlData += (dat.balance_diff >= 0 ? "+" : "") + diff;
                        htmlData += "</a>";

                        htmlData += ")";
                        htmlData += "\n";

                        total += parseFloat(dat.balance);
                    });

                    var balance = formatBalance(total);
                    var balws = genWhitespace(balance, 6);

                    htmlData += "<a href=\"#\" coin=\"" + coin + "\" exchange=\"0\" mode=\"1\" class=\"showGraph\" title=\"Comparative rate\">";
                    htmlData += "T</a> = ";
                    htmlData += balws;
                    htmlData += "<a href=\"#\" coin=\"" + coin + "\" exchange=\"0\" mode=\"0\" class=\"showGraph\" title=\"Total balance\">";
                    htmlData += balance;
                    htmlData += "</a>";
                    htmlData += "\n";
                    htmlData += "\n";

                    if (totalBalance == 0) {
                        htmlData += "</span>";
		    }
                });

                $("#wallets").html("<pre>" + htmlData + "</pre>");
            }
        });
    }

    function updateStats() {

        setTimeout(updateStats, 1000);

        if (stats === null) {
            return;
        }

        var autobuy = new String(stats.autobuy_funds);
        autobuy = autobuy.substr(0, autobuy.indexOf('.') + 6);

        var htmlData = "";
        htmlData += "     Total trades: " + stats.trades + "\n";
        if (totalPL != null) {
            htmlData += "   Unrealized P&L: " + totalPL + "\n";
        }
        if (realizedPL != null) {
            htmlData += "     Realized P&L: " + realizedPL + "\n";
        }
        if (profitableTrades != null) {
            if (plMode == "summary") {
                htmlData += "Profitable trades\n" +
                            "    past 24 hours: ";
            } else {
                htmlData += "Profitable trades: ";
            }
	    htmlData += rnd2(profitableTrades) + "%\n";
        }
        htmlData += "    Autobuy funds: " + autobuy + "\n\n";
        htmlData += "  Next manage-run: " + timeLeft(stats.next_management) + "\n";
        htmlData += " Next take profit: " + timeLeft(stats.next_take_profit) + "\n";

        $("#stats").html("<pre>" + htmlData + "</pre>");
    }

    function refreshStats() {

        statsTimeout = setTimeout(refreshStats, 60000);

        $.ajax({
            url: "ajax.php?func=stats",
            type: "GET",
            cache: false,
            success: function(data) {
                if (!uiDisabled && data.error) {
                  alert(data.error);
                  uiDisabled = true;
                }

                stats = data;
                if (stats.admin_ui) {
                    $("#admin-ui").addClass("enabled");
                    initAdminUI();
                } else {
                    $("#admin-ui").removeClass("enabled");
                }
            }
        });
    }

    function initAdminUI() {
        if (adminUIReady) {
            return;
        }
        initBotStatus();
        initBotCtrl();
        initAutoBuyFundSetter();
        initConfigEditor();
        adminUIReady = true;
    }

    function initBotStatus() {
        $.ajax({
            url: "admin-ui.php",
            type: "POST",
            cache: false,
            data: {
              action: "get_bot_status"
            },
            success: function(data) {
                botStatus = data;
                if (hideStatus) {
                  return;
                }
                var tooltip = data.status + " due to " + (data.healthy ? "being paused" : "a problem");
                $("#bot-status").css({color:data.healthy ? "green" : "red"}).text(data.status).attr({title:tooltip}).show();
            }
        });

        setTimeout(initBotStatus, 3000);
    }

    function initBotCtrl() {
        var ctrl = $("#bot-ctrl");
        ctrl.hide();

        function phase() {
          if (!botStatus) {
            setTimeout(phase, 100);
            return;
          }

          var isRunning = (botStatus.status == "Running");
          ctrl[0].value = isRunning ? "Pause" : "Resume";
          ctrl.show();
          ctrl.click(function onClick() {
            var action;
            var isRunning = (botStatus.status == "Running");
            if (isRunning) {
              action = "pause_bot";
            } else {
              action = "resume_bot";
            }
            ctrl[0].disabled = true;
            $("#bot-status").hide();
            hideStatus = true;
            $.ajax({
                url: "admin-ui.php",
                type: "POST",
                cache: false,
                data: {
                  action: action
                },
                success: function manipulateBot() {
                  var expected = isRunning ? "Paused" : "Running";
                  waitForBotOperation(expected, function() {
                    hideStatus = false;
                    ctrl[0].disabled = false;
                    if (isRunning) {
                      ctrl[0].value = "Resume";
                    } else {
                      ctrl[0].value = "Pause";
                    }
                  });
                }
            });
          });
        }

        setTimeout(phase, 100);
    }

    function waitForBotOperation(expected, callback) {
        if (botStatus.status == expected) {
          callback();
          return;
        }
        setTimeout(waitForBotOperation, 100, expected, callback);
    }

    function initAutoBuyFundSetter() {
        var ctrl = $("#autobuy")[0];
        ctrl.value = stats.autobuy_funds;

        $("#autobuy-update").click(function() {
            $.ajax({
                url: "admin-ui.php",
                type: "POST",
                cache: false,
                data: {
                  action: "set_autobuy_funds",
                  value: ctrl.value
                },
                success: function() {
                    $("#autobuy-status").css({color:"green"}).text("Succeeded").show();
                    setTimeout(function() {
                      if (statsTimeout) {
                        clearTimeout(statsTimeout);
                      }
		      refreshStats();
                      $("#autobuy-status").fadeOut();
                    }, 3000);
                },
                error: function() {
                    $("#autobuy-status").css({color: "red"}).text("Error while updating the autobuy funds").show();
                    setTimeout(function() {
                      $("#autobuy-status").fadeOut();
                    }, 3000);
                }
            });
        });
    }

    function initConfigEditor() {
      $.ajax({
          url: "admin-ui.php",
          type: "POST",
          cache: false,
          data: {
            action: "get_config_fields"
          },
          success: function(data) {
              var sections = Object.keys(data);
              var htmlData = "";
              for (var i = 0; i < sections.length; ++i) {
                  var section = sections[i];
                  htmlData += "<div class=\"section\">[" + section + "]</div>";
                  for (var j = 0; j < data[ section ].length; ++j) {
                      var item = data[ section ][ j ];
                      var boolean = (item.value == 'true' || item.value == 'false');
                      htmlData += "<div class=\"item\" data-name=\"" + sections[i] + "." + item.name + "\">" + item.name +
                                  "</div><div class=\"value\"><input type=\"" + (boolean ? 'checkbox' : 'text') +
                                  "\" id=\"" + item.name +
                                  "\" value=\"" + (('value' in item) ? item.value : '') +

                                  "\"" + (item.value == 'true' ? ' checked' : '') + "></div><div class=\"description\">" + (('description' in item) ? item.description : '') +
                                  "</div>";
                  }
              }
              htmlData += "<input type=\"button\" value=\"Update\" id=\"save-config\"> ";
              htmlData += "<span id=\"config-status\"></span>";
              $(document).on("click", "#save-config", onSaveConfig);
              $("#config-editor").html(htmlData);
          },
      });
    }

    function onSaveConfig() {
      var list = $("#config-editor .item");
      var results = [];
      for (var i = 0; i < list.length; ++i) {
          var name = list[i].dataset.name;
          var value = list[i].nextSibling.firstChild;
          if (value.type == "checkbox") {
              value = value.checked;
          } else {
              value = value.value;
          }
          results.push({name:name, value:value});
      }
      $.ajax({
          url: "admin-ui.php",
          type: "POST",
          cache: false,
          data: {
            action: "set_config_fields",
            data: results
          },
          success: function(data) {
              $("#config-status").css({color:"green"}).text("Succeeded").show();
              setTimeout(function() {
                $("#config-status").fadeOut();
              }, 3000);
          },
          error: function() {
              $("#config-status").css({color: "red"}).text("Error while updating the config").show();
              setTimeout(function() {
                $("#config-status").fadeOut();
              }, 3000);
          },
      });
    }

    initSmoothCheckbox();

    refreshStats();
    updateStats();

    updateAge();

    updateGraph();
    updateWallets();
    updateManagement();
    updateTrades();

    updateXfers();
    updateAlerts();
    updateLog();
    updatePL();
});

