# ShiftSwap - Clinic Roster Management

## Setup Instructions
1. Start XAMPP (Apache and MySQL).
2. Create a database named `shiftswap`.
3. Place this repository in `htdocs/ShiftSwap`.
4. Access `http://localhost:8080/ShiftSwap/seed.php` once to initialize the state.
5. Access the application at `http://localhost:8080/ShiftSwap/index.html`.

## Assumptions Made
- **Time Zones:** As specified, times are treated strictly as local `YYYY-MM-DDTHH:MM` strings[cite: 11]. Database comparisons use standard string sorting and Unix timestamp conversions.
- **Weekly Boundaries:** For the 40-hour limit rule, a week is calculated strictly from Monday 00:00 to the next Monday 00:00 using PHP's ISO-8601 week year and week number (`oW`) format[cite: 11].
- **State Persistence:** MySQL is used with InnoDB to ensure state survives restarts and to utilize row-level locking (`FOR UPDATE`) to prevent race conditions when multiple staff claim simultaneously[cite: 12].
