// Fix: Removed file content markers that were causing syntax errors.
import React from 'react';
import { TOP_SELLERS } from '../constants';
import type { Product } from '../types';

const SellerCard: React.FC<{ product: Product; className?: string }> = ({ product, className = '' }) => {
  return (
    <div className={`group relative rounded-2xl overflow-hidden shadow-lg ${className}`}>
      <img
        src={product.imageUrl}
        alt={product.name}
        className="absolute inset-0 w-full h-full object-cover transition-transform duration-500 ease-in-out group-hover:scale-110"
      />
      <div className="absolute inset-0 bg-gradient-to-t from-black/70 via-black/40 to-transparent"></div>
      <div className="relative h-full flex flex-col justify-end p-6 text-white">
        <div>
          <p className="text-sm uppercase tracking-wider font-medium opacity-80">{product.category}</p>
          <h3 className="text-2xl lg:text-3xl font-bold mt-1 leading-tight">{product.name}</h3>
        </div>
        <div className="mt-4 flex justify-between items-center">
          <p className="text-3xl lg:text-4xl font-extrabold tracking-tight">${product.price.toFixed(2)}</p>
          <button 
            className="bg-white/20 hover:bg-white/40 backdrop-blur-sm text-white w-12 h-12 rounded-full flex items-center justify-center transition-all duration-300 group-hover:scale-110"
            aria-label={`Add ${product.name} to cart`}
          >
            <i className="fas fa-plus"></i>
          </button>
        </div>
      </div>
    </div>
  );
};

const TopSellers: React.FC = () => {
    if (!TOP_SELLERS || TOP_SELLERS.length < 3) return null;

  return (
    <section className="py-20 sm:py-24 bg-white">
      <div className="container mx-auto px-4 sm:px-6 lg:px-8">
        <div className="text-center mb-12">
          <h2 className="text-3xl sm:text-4xl font-bold tracking-tight text-gray-900">Top Sellers This Week</h2>
          <p className="mt-4 max-w-2xl mx-auto text-lg text-gray-600">
            Discover the products our community is loving right now.
          </p>
        </div>
        <div className="grid grid-cols-1 md:grid-cols-3 md:grid-rows-2 gap-6 h-auto md:h-[600px]">
          <SellerCard product={TOP_SELLERS[0]} className="md:col-span-2 md:row-span-2 min-h-[300px]" />
          <SellerCard product={TOP_SELLERS[1]} className="md:col-span-1 min-h-[290px]" />
          <SellerCard product={TOP_SELLERS[2]} className="md:col-span-1 min-h-[290px]" />
        </div>
      </div>
    </section>
  );
};

export default TopSellers;
