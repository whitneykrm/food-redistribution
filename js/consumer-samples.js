const SHOW_SAMPLE_LISTINGS = true;

function inHours(hours) {
    const d = new Date(Date.now() + hours * 3600000);
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:00`;
}
function makeSample(id, business, city, address, item, quantity, original, discounted, hours, image, lat, lng) {
    return {
        listings_id: 'sample-' + id,
        is_sample: true,
        item_name: item,
        image_path: image,
        quantity: quantity,
        original_price: original,
        discounted_price: discounted,
        expiry_time: inHours(hours),
        business_name: business,
        city: city,
        address: address,
        latitude: lat,
        longitude: lng,
        points: Math.floor(discounted / 10),
        points_cost: Math.ceil(discounted)
    };
}

const SAMPLE_LISTINGS = SHOW_SAMPLE_LISTINGS ? [
    makeSample(1, 'Sublime Bakery', 'Westlands', 'Mpaka Road, Westlands', 'Assorted evening pastries', 4, 500, 150, 3, 'images/samples/pastries.jpg', -1.2630, 36.8030),
    makeSample(2, 'Kilimani Bakery', 'Kilimani', 'Argwings Kodhek Road, Kilimani', 'Fresh pastry box', 5, 700, 350, 5, 'images/samples/pastry-box.jpg', -1.2894, 36.7870),
    makeSample(3, 'Halal Grill House', 'Eastleigh', '1st Avenue, Eastleigh', 'Mixed grill boxes', 3, 600, 250, 4, 'images/samples/mixed-grill.jpg', -1.2740, 36.8440),
] : [];