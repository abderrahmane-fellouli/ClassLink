/** School wall-clock inputs use Casablanca, independently of the device zone. */
export function schoolDateTimeInput(value: string): string {
  const parts = new Intl.DateTimeFormat("en-CA", {
    timeZone: "Africa/Casablanca",
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
    hourCycle: "h23",
  }).formatToParts(new Date(value));
  const get = (type: string) => parts.find((p) => p.type === type)?.value ?? "";
  return `${get("year")}-${get("month")}-${get("day")}T${get("hour")}:${get("minute")}`;
}

export function schoolDateTimeToIso(value: string): string {
  if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?$/.test(value)) return value;
  const wall = Date.parse(`${value}Z`);
  let instant = wall;
  // Morocco suspends UTC+1 during Ramadan. Resolve using IANA data, not a fixed offset.
  for (let i = 0; i < 3; i++) {
    const seconds = String(new Date(instant).getUTCSeconds()).padStart(2, "0");
    const displayed = Date.parse(
      `${schoolDateTimeInput(new Date(instant).toISOString())}:${seconds}Z`,
    );
    const correction = wall - displayed;
    instant += correction;
    if (correction === 0) break;
  }
  return new Date(instant).toISOString();
}
