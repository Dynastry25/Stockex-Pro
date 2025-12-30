-- Add additional fields to trades table to support CSV import data
ALTER TABLE trades 
ADD COLUMN market VARCHAR(10) AFTER trade_reference,
ADD COLUMN house VARCHAR(50) AFTER market,
ADD COLUMN broker VARCHAR(50) AFTER house,
ADD COLUMN trader_code VARCHAR(20) AFTER broker,
ADD COLUMN principal VARCHAR(50) AFTER trader_code,
ADD COLUMN counterparty VARCHAR(50) AFTER principal,
ADD COLUMN isin_code VARCHAR(20) AFTER counterparty,
ADD COLUMN exchange_reference VARCHAR(50) AFTER isin_code,
ADD COLUMN additional_reference VARCHAR(50) AFTER exchange_reference,
ADD COLUMN trade_time TIME AFTER trade_date,
ADD COLUMN origin VARCHAR(50) AFTER trade_time,
ADD COLUMN capacity VARCHAR(50) AFTER origin;

-- Add indexes for new fields
CREATE INDEX idx_trades_market ON trades(market);
CREATE INDEX idx_trades_broker ON trades(broker);
CREATE INDEX idx_trades_isin ON trades(isin_code);
CREATE INDEX idx_trades_exchange_ref ON trades(exchange_reference);
