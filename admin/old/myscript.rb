require 'rest-client'
response = RestClient.get('https://legenda.co/refract/cors-proxy.php?url=https://www.ndbc.noaa.gov/data/realtime2/41112.spec')
puts response.body 