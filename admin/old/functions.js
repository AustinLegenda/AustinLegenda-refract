/**function refraction(L1, d1, L2, d2) {
    const g = 9.8;
    const k1 = 2 * Math.PI / L1;
    const k2 = 2 * Math.PI / L2;
    const n1 = Math.sqrt(g / (L1 / 2 / Math.PI));
    const n2 = Math.sqrt(g / (L2 / 2 / Math.PI));
    const sinTheta1 = n1 * k1 * d1 / Math.sqrt(g * L1);
    const sinTheta2 = n2 * k2 * d2 / Math.sqrt(g * L2);
    const theta1 = Math.asin(sinTheta1);
    const theta2 = Math.asin(sinTheta2);
    const delta = (k1 / Math.cos(theta1) - k2 / Math.cos(theta2)) * d2;
    return delta;
  }
  
  console.log(refraction(100, 10, 50, 5));*/
  
/**var requests = 0;
var limit = 6;
var resetTime = 24 * 60 * 60 * 1000; // 24 hours in milliseconds
var resetDate = new Date(Date.now() + resetTime);

var xhr = new XMLHttpRequest();
xhr.open("GET", "https://legenda.co/refract/cors-proxy.php?url=https://www.ndbc.noaa.gov/data/realtime2/41112.spec", true);
xhr.onreadystatechange = function() {
  if (xhr.readyState === 4 && xhr.status === 200) {
    if (requests < limit && Date.now() < resetDate.getTime()) {
      requests++;
      console.log(xhr.responseText);
    } else {
      console.log("You have reached the limit of " + limit + " requests per day. Please try again tomorrow.");
    }
  }
};
xhr.send();*/


/**function parseWaveData(data) {
  // Parse data string into usable object
  let lines = data.split("\n");
  let waveData = {};

  for (let line of lines) {
    let parts = line.split("=");
    let key = parts[0].trim();
    let value = parts[1].trim();
    waveData[key] = value;
  }
  console.log(`Data from parseWaveData: ${JSON.stringify(waveData)}`);
  return waveData;
}

function calculateRefraction(waveHeight, waveDirection, wavePeriod, coastlineOrientation) {
  // Simple refraction model
  let refractionAngle = 0.03 * waveHeight / wavePeriod + coastlineOrientation;

  return refractionAngle;
}

async function determineRefraction() {
  let waveData = await retrieveWaveData();
  let parsedData = parseWaveData(waveData);
  let coastlineOrientation = 98; // Example coastline orientation in degrees
  let targetRefractionAngle = 5;

  let refractionAngle = calculateRefraction(parsedData.waveHeight, parsedData.waveDirection, parsedData.wavePeriod, coastlineOrientation);

  if (Math.abs(refractionAngle - targetRefractionAngle) < 1) {
    console.log("This stretch of coast would be good for surfing with refraction approximately five degrees from perpendicular");
  } else {
    console.log("This stretch of coast would not be good for surfing with refraction not approximately five degrees from perpendicular");
  }
}

determineRefraction();*/
